<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class VariantSummary
{
    public function __construct(
        public string $id,
        public ?string $sku = null,
        public ?int $stockQuantity = null,
        public array $raw = [],
    ) {}
}
