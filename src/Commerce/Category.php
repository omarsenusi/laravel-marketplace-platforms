<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class Category
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $status = null,
        public ?string $image = null,
        public ?int $parentId = null,
        public array $raw = [],
    ) {}
}
