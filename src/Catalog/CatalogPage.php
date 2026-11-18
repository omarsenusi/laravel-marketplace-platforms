<?php

namespace Omarsenusi\MarketplacePlatforms\Catalog;

/**
 * One page of normalized products from a catalog reader.
 */
final readonly class CatalogPage
{
    /**
     * @param  list<StoreProduct>  $products
     */
    public function __construct(
        public array $products,
        public ?int $nextPage = null,
        public bool $deferred = false,
        public int $retryAfter = 0,
        public ?string $error = null,
    ) {}

    /**
     * @param  list<StoreProduct>  $products
     */
    public static function ok(array $products, ?int $nextPage = null): self
    {
        return new self($products, $nextPage);
    }

    public static function deferred(int $retryAfter): self
    {
        return new self([], null, true, max(0, $retryAfter));
    }

    public static function failed(?string $error): self
    {
        return new self([], null, false, 0, $error);
    }
}
