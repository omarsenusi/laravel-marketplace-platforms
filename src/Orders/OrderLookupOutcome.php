<?php

namespace Omarsenusi\MarketplacePlatforms\Orders;

/**
 * Outcome of a single-driver order lookup.
 *
 * $countFailure marks a verified "no match" (unknown order or contact
 * mismatch) so the caller can feed the per-order brute-force guard.
 * Transport problems (gateway deferred/failure) never count.
 */
final class OrderLookupOutcome
{
    public function __construct(
        public readonly array $payload,
        public readonly bool $countFailure = false,
    ) {}
}
