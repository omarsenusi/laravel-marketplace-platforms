<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class Coupon
{
    public function __construct(
        public string $id,
        public ?string $code = null,
        public ?string $type = null,
        public ?string $status = null,
        public array $raw = [],
    ) {}
}
