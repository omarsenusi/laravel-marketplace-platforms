<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification;

/**
 * Result of running a single verification strategy against a request.
 */
final class VerificationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $strategyId,
        public readonly ?string $reason = null,
    ) {}

    public static function ok(string $strategyId): self
    {
        return new self(true, $strategyId);
    }

    public static function fail(string $strategyId, string $reason): self
    {
        return new self(false, $strategyId, $reason);
    }
}
