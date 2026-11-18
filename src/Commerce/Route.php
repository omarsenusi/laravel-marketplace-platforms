<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class Route
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $type = null,
        public ?bool $status = null,
        public ?int $priority = null,
        public ?string $strategy = null,
        public array $raw = [],
    ) {}
}
