<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class VariantQuantity
{
    public function __construct(
        public string $productId,
        public ?string $variantId = null,
        public ?string $sku = null,
        public ?int $quantity = null,
        public bool $unlimited = false,
        public ?int $soldQuantity = null,
        public array $raw = [],
    ) {}
}
