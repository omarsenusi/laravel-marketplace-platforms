<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class Transaction
{
    public function __construct(
        public string $id,
        public ?int $orderId = null,
        public ?int $referenceId = null,
        public ?Money $total = null,
        public ?string $paymentProvider = null,
        public array $raw = [],
    ) {}
}
