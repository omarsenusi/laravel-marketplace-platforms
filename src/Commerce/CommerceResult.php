<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Api\ApiResult;

/**
 * Typed outcome for Commerce capability calls.
 *
 * Never throws for HTTP failures: $ok is false with the upstream $status,
 * Salla's $errorCode/{code,message} and validation $fields instead.
 * $data carries the mapped DTO (or DTO list, or true for bare
 * acknowledge responses) on success and is null otherwise. $raw is the
 * opaque upstream payload for anything not mapped onto the DTO.
 *
 * $forbidden is true on HTTP 403 (e.g. a store whose app was not
 * approved for dropshipping settlements) so callers can branch on it
 * without string-matching error codes. $rateLimitRemaining and
 * $retryAfterHeader mirror the upstream pacing headers when present.
 */
final readonly class CommerceResult
{
    /**
     * @param  array<string, list<string>>|null  $fields
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public bool $ok,
        public int $status,
        public mixed $data = null,
        public ?string $error = null,
        public ?string $errorCode = null,
        public ?array $fields = null,
        public bool $deferred = false,
        public int $retryAfter = 0,
        public bool $forbidden = false,
        public ?array $raw = null,
        public ?int $rateLimitRemaining = null,
        public ?int $retryAfterHeader = null,
    ) {}

    public static function ok(mixed $data, int $status = 200, ?array $raw = null): self
    {
        return new self(true, $status, $data, null, null, null, false, 0, false, $raw);
    }

    /**
     * @param  array<string, list<string>>|null  $fields
     * @param  array<string, mixed>|null  $raw
     */
    public static function fail(
        int $status,
        ?string $error = null,
        ?string $errorCode = null,
        ?array $fields = null,
        ?array $raw = null,
        ?int $rateLimitRemaining = null,
        ?int $retryAfterHeader = null,
    ): self {
        return new self(false, $status, null, $error, $errorCode, $fields, false, 0, $status === 403, $raw, $rateLimitRemaining, $retryAfterHeader);
    }

    public static function deferred(int $retryAfter): self
    {
        return new self(false, 0, null, 'deferred', 'deferred', null, true, $retryAfter);
    }

    public static function refused(string $error): self
    {
        return new self(false, 0, null, $error, $error, null);
    }

    /**
     * Lift a gateway answer, mapping 2xx $json['data'] through $map.
     * $map receives the `data` node (or the whole body when there is no
     * `data` key) plus the full decoded body for pagination metadata,
     * and returns the DTO; it may throw on unexpected shapes — that
     * surfaces as a mapping failure, never a throw.
     *
     * @param  callable(mixed, array<string, mixed>): mixed  $map
     */
    public static function fromApiResult(ApiResult $result, callable $map): self
    {
        if ($result->deferred) {
            return self::deferred($result->retryAfter);
        }

        if (! $result->ok || ! is_array($result->json)) {
            return self::fail(
                $result->status,
                $result->error,
                $result->errorCode,
                $result->fields,
                is_array($result->json) ? $result->json : null,
                $result->rateLimitRemaining,
                $result->retryAfterHeader,
            );
        }

        $node = $result->json['data'] ?? $result->json;

        try {
            $data = $map($node, $result->json);
        } catch (\Throwable) {
            return self::fail($result->status, 'mapping_failed', 'mapping_failed', null, $result->json);
        }

        return self::ok($data, $result->status, $result->json);
    }
}
