<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * An instant settlement hold.
 *
 * Salla's settlement payload carries no currency (the amount is a bare
 * decimal), so $currency is the caller-supplied ISO code on create and
 * null on reads.
 *
 * @param  array<string, mixed>  $raw
 */
final readonly class Settlement
{
    public function __construct(
        public string $id,
        public int $referenceId,
        public int $amountMinor,
        public ?string $currency,
        public SettlementStatus $status,
        public array $raw = [],
    ) {}
}
