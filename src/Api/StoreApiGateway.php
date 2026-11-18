<?php

namespace Omarsenusi\MarketplacePlatforms\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Omarsenusi\MarketplacePlatforms\Auth\PlatformApiClient;
use Omarsenusi\MarketplacePlatforms\Auth\TokenStore;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Throwable;

/**
 * Per-store outbound API gateway for Salla/Zid calls.
 *
 * WHY one more layer instead of calling PlatformApiClient directly:
 * both marketplaces enforce per-store leaky-bucket rate limits, and a
 * burst of realtime traffic must never starve (or get blocked by)
 * background catalog work — or vice versa. This gateway splits the
 * per-connection budget into two lanes ('realtime' and 'background',
 * shares under the `platforms.api.lanes` config key) via Laravel's
 * RateLimiter, opens a short circuit breaker on 429/5xx/transport
 * errors, and caches successful GETs when asked.
 *
 * Writes (POST/PUT/PATCH/DELETE) are never auto-retried: they are not
 * assumed idempotent, so a 5xx/429 is returned as a failure (with the
 * upstream status, error code and validation fields) and the caller
 * decides. The single exception is 401: the store token is refreshed
 * once through the normal token machinery (driver refresh +
 * TokenStore) and the write is retried exactly once.
 *
 * Never throws for HTTP failures (returns ApiResult::failure) and
 * never logs tokens — only connection id, driver, lane, path, status.
 */
final class StoreApiGateway
{
    public const LANE_REALTIME = 'realtime';

    public const LANE_BACKGROUND = 'background';

    public function get(
        PlatformConnection $connection,
        string $path,
        array $query = [],
        string $lane = self::LANE_REALTIME,
        int $cacheSeconds = 0,
        array $headers = [],
    ): ApiResult {
        return $this->send('GET', $connection, $path, $query, [], $lane, $cacheSeconds, $headers);
    }

    public function post(
        PlatformConnection $connection,
        string $path,
        array $body = [],
        array $query = [],
        string $lane = self::LANE_REALTIME,
        array $headers = [],
    ): ApiResult {
        return $this->send('POST', $connection, $path, $query, $body, $lane, 0, $headers);
    }

    public function put(
        PlatformConnection $connection,
        string $path,
        array $body = [],
        array $query = [],
        string $lane = self::LANE_REALTIME,
        array $headers = [],
    ): ApiResult {
        return $this->send('PUT', $connection, $path, $query, $body, $lane, 0, $headers);
    }

    public function patch(
        PlatformConnection $connection,
        string $path,
        array $body = [],
        array $query = [],
        string $lane = self::LANE_REALTIME,
        array $headers = [],
    ): ApiResult {
        return $this->send('PATCH', $connection, $path, $query, $body, $lane, 0, $headers);
    }

    public function delete(
        PlatformConnection $connection,
        string $path,
        array $query = [],
        string $lane = self::LANE_REALTIME,
        array $headers = [],
    ): ApiResult {
        return $this->send('DELETE', $connection, $path, $query, [], $lane, 0, $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function send(
        string $method,
        PlatformConnection $connection,
        string $path,
        array $query,
        array $body,
        string $lane,
        int $cacheSeconds,
        array $headers,
    ): ApiResult {
        $connection->loadMissing('platform');

        if ($connection->status !== ConnectionStatus::Active || $connection->uninstalled_at !== null) {
            return ApiResult::refused('connection_inactive');
        }

        $isWrite = $method !== 'GET';
        $lane = $lane === self::LANE_BACKGROUND ? self::LANE_BACKGROUND : self::LANE_REALTIME;
        $connectionId = (string) $connection->getKey();

        // Cache first: a cached answer costs no API budget, so neither an
        // open breaker nor a spent lane may hide it. Writes never read it.
        if (! $isWrite && $cacheSeconds > 0) {
            $cached = Cache::get($this->cacheKey($connectionId, $path, $query));
            if (is_array($cached) && ($cached['ok'] ?? false) === true) {
                return ApiResult::ok((int) ($cached['status'] ?? 200), $cached['json'] ?? null);
            }
        }

        $limit = $this->rateLimitPerMinute($connection);
        $laneBudget = (int) floor($limit * $this->laneShare($lane));
        $laneBudget = max(1, $laneBudget);

        // Circuit breaker: a recent 429/5xx/transport failure means the
        // store's bucket is empty — don't spend an HTTP call to re-learn it.
        $breakerKey = "platforms:api:{$connectionId}:breaker";
        if (Cache::has($breakerKey)) {
            return ApiResult::deferred($this->secondsUntilKeyFrees($breakerKey));
        }

        // The background lane additionally yields when the last observed
        // headroom dropped below 40% — background work must back off
        // before realtime traffic starts failing.
        if ($lane === self::LANE_BACKGROUND && $this->headroomLow($connectionId)) {
            return ApiResult::deferred(30);
        }

        $laneKey = "platforms:api:{$connectionId}:{$lane}";
        if (RateLimiter::tooManyAttempts($laneKey, $laneBudget)) {
            return ApiResult::deferred(RateLimiter::availableIn($laneKey));
        }

        RateLimiter::hit($laneKey, 60);

        $response = $this->request($connection, $method, $path, $query, $body, $lane, $headers, $isWrite);

        if ($response === null) {
            $this->openBreaker($breakerKey, 60);

            return ApiResult::failure(0, 'transport_error');
        }

        // One token refresh + one retry on 401, for reads and writes
        // alike. Anything after that (or a failed refresh) is terminal.
        if ($response->status() === 401 && $this->refreshTokens($connection)) {
            $connection->refresh();
            RateLimiter::hit($laneKey, 60);
            $response = $this->request($connection, $method, $path, $query, $body, $lane, $headers, $isWrite);

            if ($response === null) {
                $this->openBreaker($breakerKey, 60);

                return ApiResult::failure(0, 'transport_error');
            }
        }

        $this->recordHeadroom($connectionId, $response);

        $status = $response->status();
        $remaining = $this->headerInt($response, 'X-RateLimit-Remaining');
        $retryAfterHeader = $this->headerInt($response, 'Retry-After');

        if ($status === 429 || ($status >= 500 && $status <= 599)) {
            $this->openBreaker($breakerKey, $retryAfterHeader ?? 60);

            // Writes are never auto-retried (not idempotent): surface the
            // failure with the upstream status so the caller decides.
            // Reads keep the old deferred contract.
            if ($isWrite) {
                return $this->parsedFailure($response, $status, $remaining, $retryAfterHeader);
            }

            return ApiResult::deferred($this->secondsUntilKeyFrees($breakerKey));
        }

        if ($status >= 200 && $status < 300) {
            $json = $response->json();
            $json = is_array($json) ? $json : null;

            if (! $isWrite && $cacheSeconds > 0) {
                Cache::put(
                    $this->cacheKey($connectionId, $path, $query),
                    ['ok' => true, 'status' => $status, 'json' => $json],
                    $cacheSeconds
                );
            }

            return ApiResult::ok($status, $json, $remaining, $retryAfterHeader);
        }

        // 401/403/404/422 (and other 4xx) are terminal for this call:
        // no breaker — a missing product is not a sick upstream.
        return $this->parsedFailure($response, $status, $remaining, $retryAfterHeader);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function request(
        PlatformConnection $connection,
        string $method,
        string $path,
        array $query,
        array $body,
        string $lane,
        array $headers,
        bool $isWrite,
    ): ?Response {
        try {
            $request = PlatformApiClient::for($connection);

            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }

            // Never let the HTTP layer auto-retry: realtime traffic must
            // fail fast instead of sleeping on Retry-After (up to 30s),
            // and writes are never retried anywhere below the caller.
            if ($lane === self::LANE_REALTIME || $isWrite) {
                $request = $request->withOptions(['platforms_retried_429' => true]);
            }

            if ($lane === self::LANE_REALTIME) {
                $request = $request->timeout(8);
            }

            $url = $query === [] ? $path : $path.'?'.http_build_query($query);

            return match ($method) {
                'POST' => $request->post($url, $body),
                'PUT' => $request->put($url, $body),
                'PATCH' => $request->patch($url, $body),
                'DELETE' => $request->delete($url),
                default => $request->get($url),
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Refresh the store token once through the normal token machinery
     * (driver refresh + TokenStore persist). True when fresh tokens were
     * stored and the call may be retried once.
     */
    private function refreshTokens(PlatformConnection $connection): bool
    {
        try {
            $set = $connection->platform->driver()->refresh($connection);
        } catch (Throwable) {
            return false;
        }

        if (! is_string($set->refreshToken) && ! is_string($set->accessToken)
            && ! is_string($set->managerToken) && ! is_string($set->authorizationToken)) {
            return false;
        }

        try {
            app(TokenStore::class)->persistRefreshed($connection->refresh(), $set);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Map a Salla error body ({status, success, error:{code,message,fields}})
     * onto an ApiResult failure. Never throws on unexpected shapes.
     */
    private function parsedFailure(Response $response, int $status, ?int $remaining, ?int $retryAfterHeader): ApiResult
    {
        try {
            $json = $response->json();
            $json = is_array($json) ? $json : [];
        } catch (Throwable) {
            $json = [];
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = $error['code'] ?? null;
        $code = is_scalar($code) ? (string) $code : null;

        $fields = $error['fields'] ?? null;
        $fields = is_array($fields) ? $this->stringFields($fields) : null;

        return ApiResult::failure($status, "http_{$status}", $code, $fields, $remaining, $retryAfterHeader);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, list<string>>|null
     */
    private function stringFields(array $fields): ?array
    {
        $out = [];

        foreach ($fields as $key => $messages) {
            if (! is_string($key)) {
                continue;
            }

            $list = is_array($messages) ? $messages : [$messages];
            $list = array_values(array_filter(
                array_map(static fn ($m): ?string => is_scalar($m) ? (string) $m : null, $list),
                static fn ($m): bool => $m !== null && $m !== '',
            ));

            if ($list !== []) {
                $out[$key] = $list;
            }
        }

        return $out === [] ? null : $out;
    }

    private function headerInt(Response $response, string $name): ?int
    {
        try {
            $header = $response->header($name);
        } catch (Throwable) {
            return null;
        }

        $header = is_array($header) ? ($header[0] ?? '') : (string) $header;
        $header = trim($header);

        return $header !== '' && is_numeric($header) ? (int) $header : null;
    }

    private function laneShare(string $lane): float
    {
        $share = config(
            "platforms.api.lanes.{$lane}",
            $lane === self::LANE_BACKGROUND ? 0.30 : 0.90,
        );

        if (! is_numeric($share)) {
            return $lane === self::LANE_BACKGROUND ? 0.30 : 0.90;
        }

        return min(1.0, max(0.01, (float) $share));
    }

    private function rateLimitPerMinute(PlatformConnection $connection): int
    {
        $config = is_array($connection->platform->config) ? $connection->platform->config : [];
        $override = $config['api']['rateLimitPerMinute'] ?? null;

        if (is_numeric($override) && (int) $override > 0) {
            return (int) $override;
        }

        $driverKey = (string) $connection->platform->driver()->key();
        $configured = (int) config("platforms.api.rate_limits.{$driverKey}", 0);

        if ($configured > 0) {
            return $configured;
        }

        return $driverKey === 'zid' ? 60 : 120;
    }

    private function recordHeadroom(string $connectionId, Response $response): void
    {
        try {
            $remaining = $response->header('X-RateLimit-Remaining');
            $limit = $response->header('X-RateLimit-Limit');
        } catch (Throwable) {
            return;
        }

        // Header may be string|string[]|null depending on the client.
        $remaining = is_array($remaining) ? ($remaining[0] ?? null) : $remaining;
        $limit = is_array($limit) ? ($limit[0] ?? null) : $limit;

        if (! is_numeric($remaining) || ! is_numeric($limit)) {
            return;
        }

        Cache::put(
            "platforms:api:{$connectionId}:headroom",
            ['remaining' => (int) $remaining, 'limit' => (int) $limit, 'at' => time()],
            60
        );
    }

    private function headroomLow(string $connectionId): bool
    {
        $headroom = Cache::get("platforms:api:{$connectionId}:headroom");

        if (! is_array($headroom) || ! isset($headroom['remaining'], $headroom['limit'])) {
            return false;
        }

        $limit = (int) $headroom['limit'];

        if ($limit <= 0) {
            return false;
        }

        return ((int) $headroom['remaining']) < (0.40 * $limit);
    }

    private function openBreaker(string $breakerKey, int $seconds): void
    {
        $ttl = min(300, max(1, $seconds));
        Cache::put($breakerKey, time() + $ttl, $ttl);
    }

    private function secondsUntilKeyFrees(string $key): int
    {
        // The breaker stores its own expiry timestamp as the value so
        // callers get a truthful retryAfter on every cache driver
        // (Cache::get returns the value, never the TTL).
        $expiresAt = Cache::get($key);

        if (is_numeric($expiresAt)) {
            return max(1, (int) $expiresAt - time());
        }

        return 30;
    }

    private function cacheKey(string $connectionId, string $path, array $query): string
    {
        ksort($query);

        return 'platforms:api:cache:'.$connectionId.':'.sha1($path.'?'.http_build_query($query));
    }
}
