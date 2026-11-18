<?php

namespace Omarsenusi\MarketplacePlatforms\Api;

/**
 * Small value object for StoreApiGateway outcomes.
 *
 * deferred=true means "don't retry right now" (lane budget exhausted,
 * circuit breaker open, or last headroom too low for the background lane).
 * Callers should fall back to cached/catalog data and retry later —
 * never spin on it. Never carries tokens or request bodies.
 *
 * errorCode/fields carry Salla's error body ({error:{code,message,fields}})
 * so write callers can react without re-parsing; rateLimitRemaining and
 * retryAfterHeader surface the pacing headers when the upstream sent them.
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
        public readonly ?string $errorCode = null,
        /** @var array<string, list<string>>|null */
        public readonly ?array $fields = null,
        public readonly ?int $rateLimitRemaining = null,
        public readonly ?int $retryAfterHeader = null,
    ) {}

    public static function ok(int $status, ?array $json, ?int $rateLimitRemaining = null, ?int $retryAfterHeader = null): self
    {
        return new self(true, $status, $json, false, 0, null, null, null, $rateLimitRemaining, $retryAfterHeader);
    }

    public static function failure(
        int $status,
        ?string $error = null,
        ?string $errorCode = null,
        ?array $fields = null,
        ?int $rateLimitRemaining = null,
        ?int $retryAfterHeader = null,
    ): self {
        return new self(false, $status, null, false, 0, $error, $errorCode, $fields, $rateLimitRemaining, $retryAfterHeader);
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
