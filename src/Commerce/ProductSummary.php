<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class ProductSummary
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $status = null,
        public array $raw = [],
    ) {}
}
