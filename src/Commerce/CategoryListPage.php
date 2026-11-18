<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  list<Category>  $categories
 */
final readonly class CategoryListPage
{
    public function __construct(
        public array $categories,
        public ?int $nextPage = null,
        public ?int $total = null,
    ) {}
}
