<?php

namespace Omarsenusi\MarketplacePlatforms\Catalog;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Per-driver product catalogue reader behind the StoreApiGateway.
 *
 * Never throws for HTTP failures — find() returns null on miss and
 * page()/search() return deferred/failed pages the caller handles.
 * The $lane is passed through to the gateway ('realtime' fails fast for
 * live visitor turns, 'background' for background indexing).
 */
interface CatalogReader
{
    public function page(PlatformConnection $connection, int $page, string $lane): CatalogPage;

    public function find(PlatformConnection $connection, string $id, string $lane): ?StoreProduct;

    public function search(PlatformConnection $connection, string $query, int $limit, string $lane): CatalogPage;

    /**
     * Retry-after seconds of the last find()/search() call when the
     * gateway deferred it, or null when the last call resolved.
     */
    public function lastDeferredAfter(): ?int;
}
