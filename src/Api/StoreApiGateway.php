<?php

namespace Omarsenusi\MarketplacePlatforms\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Omarsenusi\MarketplacePlatforms\Auth\PlatformApiClient;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Throwable;

/**
 * Per-store outbound API gateway for Salla/Zid calls.
 *
 * WHY one more layer instead of calling PlatformApiClient directly:
 * both marketplaces enforce per-store leaky-bucket rate limits, and a
 * burst of live chat traffic must never starve (or get blocked by)
 * background catalog sync — or vice versa. This gateway splits the
 * per-connection budget into two lanes ('chat' ≤90%, 'sync' ≤30% of
 * the per-minute budget) via Laravel's RateLimiter, opens a short
 * circuit breaker on 429/5xx/transport errors, and caches successful
 * GETs when asked.
 *
 * Never throws for HTTP failures (returns ApiResult::failure) and
 * never logs tokens — only connection id, driver, lane, path, status.
 */
final class StoreApiGateway
{
    public function get(
        PlatformConnection $connection,
        string $path,
        array $query = [],
        string $lane = 'chat',
        int $cacheSeconds = 0,
        array $headers = [],
    ): ApiResult {
        $connection->loadMissing('platform');

        if ($connection->status !== ConnectionStatus::Active || $connection->uninstalled_at !== null) {
            return ApiResult::refused('connection_inactive');
        }

        $lane = $lane === 'sync' ? 'sync' : 'chat';
        $connectionId = (string) $connection->getKey();

        // Cache first: a cached answer costs no API budget, so neither an
        // open breaker nor a spent lane may hide it.
        if ($cacheSeconds > 0) {
            $cached = Cache::get($this->cacheKey($connectionId, $path, $query));
            if (is_array($cached) && ($cached['ok'] ?? false) === true) {
                return ApiResult::ok((int) ($cached['status'] ?? 200), $cached['json'] ?? null);
            }
        }

        $limit = $this->rateLimitPerMinute($connection);
        $laneBudget = (int) floor($limit * ($lane === 'sync' ? 0.30 : 0.90));
        $laneBudget = max(1, $laneBudget);

        // Circuit breaker: a recent 429/5xx/transport failure means the
        // store's bucket is empty — don't spend an HTTP call to re-learn it.
        $breakerKey = "platforms:api:{$connectionId}:breaker";
        if (Cache::has($breakerKey)) {
            return ApiResult::deferred($this->secondsUntilKeyFrees($breakerKey));
        }

        // The sync lane additionally yields when the last observed
        // headroom dropped below 40% — background sync must back off
        // before live chat traffic starts failing.
        if ($lane === 'sync' && $this->headroomLow($connectionId)) {
            return ApiResult::deferred(30);
        }

        $laneKey = "platforms:api:{$connectionId}:{$lane}";
        if (RateLimiter::tooManyAttempts($laneKey, $laneBudget)) {
            return ApiResult::deferred(RateLimiter::availableIn($laneKey));
        }

        RateLimiter::hit($laneKey, 60);

        try {
            $request = PlatformApiClient::for($connection);
            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }
            if ($lane === 'chat') {
                // A visitor is waiting: never sleep on Retry-After inside a
                // chat turn (PlatformApiClient's 429 middleware can block up
                // to 30s). Fail fast and let the breaker do the backing off.
                $request = $request->withOptions(['platforms_retried_429' => true])->timeout(8);
            }
            $response = $request->get($path, $query);
        } catch (Throwable $e) {
            $this->openBreaker($breakerKey, 60);

            return ApiResult::failure(0, 'transport_error');
        }

        $this->recordHeadroom($connectionId, $response);

        $status = $response->status();

        if ($status === 429 || ($status >= 500 && $status <= 599)) {
            $this->openBreaker($breakerKey, $this->retryAfterSeconds($response, 60));

            return ApiResult::deferred($this->secondsUntilKeyFrees($breakerKey));
        }

        if ($status >= 200 && $status < 300) {
            $json = $response->json();
            $json = is_array($json) ? $json : null;

            if ($cacheSeconds > 0) {
                Cache::put(
                    $this->cacheKey($connectionId, $path, $query),
                    ['ok' => true, 'status' => $status, 'json' => $json],
                    $cacheSeconds
                );
            }

            return ApiResult::ok($status, $json);
        }

        // 401/403/404/422 (and other 4xx) are terminal for this call:
        // no breaker — a missing product is not a sick upstream.
        return ApiResult::failure($status, "http_{$status}");
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

        if ($remaining === null && $limit === null) {
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

    private function retryAfterSeconds(Response $response, int $default): int
    {
        try {
            $header = $response->header('Retry-After');
        } catch (Throwable) {
            return $default;
        }

        $header = is_array($header) ? ($header[0] ?? '') : (string) $header;
        $header = trim($header);

        if ($header !== '' && is_numeric($header)) {
            return min(300, max(1, (int) $header));
        }

        return min(300, max(1, $default));
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
