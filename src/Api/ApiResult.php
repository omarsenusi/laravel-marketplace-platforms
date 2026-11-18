<?php

namespace Omarsenusi\MarketplacePlatforms\Api;

/**
 * Small value object for StoreApiGateway outcomes.
 *
 * deferred=true means "don't retry right now" (lane budget exhausted,
 * circuit breaker open, or last headroom too low for the sync lane).
 * Callers should fall back to cached/catalog data and retry later —
 * never spin on it. Never carries tokens or request bodies.
 */
final class ApiResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?array $json,
        public readonly bool $deferred = false,
        public readonly int $retryAfter = 0,
        public readonly ?string $error = null,
    ) {}

    public static function ok(int $status, ?array $json): self
    {
        return new self(true, $status, $json);
    }

    public static function failure(int $status, ?string $error = null): self
    {
        return new self(false, $status, null, false, 0, $error);
    }

    public static function deferred(int $retryAfter): self
    {
        return new self(false, 0, null, true, max(0, $retryAfter));
    }

    public static function refused(string $error): self
    {
        return new self(false, 0, null, false, 0, $error);
    }
}
