<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class ShippingCompany
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $appId = null,
        public ?string $activationType = null,
        public array $raw = [],
    ) {}
}
