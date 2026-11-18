<?php

namespace Omarsenusi\MarketplacePlatforms\Auth;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * The ONLY way package code calls Salla/Zid APIs. Centralizes base URL,
 * auth headers (per driver token model), Accept headers, and a single
 * 429 retry honoring Retry-After.
 */
final class PlatformApiClient
{
    /**
     * Build a configured HTTP client for the connection's platform.
     * Callers chain ->get()/->post() on the returned request.
     */
    public static function for(PlatformConnection $connection): PendingRequest
    {
        $connection->loadMissing('platform');
        $platform = $connection->platform;

        $config = is_array($platform->config) ? $platform->config : [];
        $defaults = $platform->driver()->defaultConfig();

        $baseUrl = (string) ($config['api']['baseUrl'] ?? $defaults['api']['baseUrl'] ?? '');

        $tokens = is_array($connection->tokens) ? $connection->tokens : [];

        $request = Http::asJson()
            ->acceptJson()
            ->withHeaders(['Accept-Language' => app()->getLocale() ?? 'en'])
            ->baseUrl($baseUrl)
            ->timeout(15)
            ->connectTimeout(5)
            ->retry(1, 500, throw: false)
            ->withMiddleware(self::retryAfterMiddleware());

        $driverKey = $platform->driver()->key();

        if ($driverKey === 'zid') {
            // Dual model: bearer authorization token + manager token.
            $headers = [];
            if (! empty($tokens['authorization_token'])) {
                $headers['Authorization'] = 'Bearer '.$tokens['authorization_token'];
            }
            if (! empty($tokens['manager_token'])) {
                $headers['X-Manager-Token'] = $tokens['manager_token'];
            }

            return $request->withHeaders($headers);
        }

        // Salla simple model: Bearer access token.
        if (! empty($tokens['access_token'])) {
            return $request->withToken((string) $tokens['access_token']);
        }

        return $request;
    }

    /**
     * Guzzle middleware: one retry on 429, sleeping per Retry-After
     * (capped at 30s). Retries are marked via request option so the
     * retry itself never loops.
     *
     * @return callable(callable): callable
     */
    private static function retryAfterMiddleware(): callable
    {
        return static function (callable $handler): callable {
            return static function ($request, array $options) use ($handler) {
                $promise = $handler($request, $options);

                return $promise->then(static function ($response) use ($handler, $request, $options) {
                    if (method_exists($response, 'getStatusCode')
                        && $response->getStatusCode() === 429
                        && empty($options['platforms_retried_429'])) {
                        $retryAfter = 1;
                        if (method_exists($response, 'getHeaderLine')) {
                            $header = trim((string) $response->getHeaderLine('Retry-After'));
                            if ($header !== '' && is_numeric($header)) {
                                $retryAfter = max(0, min(30, (int) $header));
                            } elseif ($header !== '' && strtotime($header) !== false) {
                                $retryAfter = max(0, min(30, (int) strtotime($header) - time()));
                            }
                        }

                        if ($retryAfter > 0) {
                            sleep($retryAfter);
                        }

                        $options['platforms_retried_429'] = true;

                        return $handler($request, $options);
                    }

                    return $response;
                });
            };
        };
    }
}
