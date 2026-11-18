<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * A Salla order status (original or custom sub-status).
 *
 * @param  array<string, mixed>  $raw
 */
final readonly class OrderStatus
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $type = null,
        public ?string $message = null,
        public ?string $icon = null,
        public ?bool $isActive = null,
        public array $raw = [],
    ) {}
}
