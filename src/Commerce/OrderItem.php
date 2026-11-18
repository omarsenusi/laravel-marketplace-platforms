<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class OrderItem
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $sku = null,
        public ?int $quantity = null,
        public ?Money $price = null,
        public array $raw = [],
    ) {}
}
