<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Stock writes and quantity reads (Salla Admin API).
 *
 * Bulk adjust goes over POST /products/quantities/bulk with Salla's own
 * field spellings (`identifer_type` / `identifer`) and modes
 * increment|decrement|overwrite; verified against
 * https://docs.salla.dev/api-5394192.md. That endpoint only queues the
 * update (201 + message), so the report carries the queue message rather
 * than per-item outcomes — per-item results are returned only if Salla
 * ever includes them.
 */
interface InventoryWriter
{
    /**
     * Bulk-apply stock adjustments, chunked. Chunks go out in order and
     * stop at the first failure; the failed result still carries an
     * InventoryAdjustReport with the applied counts plus the
     * not-applied adjustments — retry only `$report->pending`,
     * re-sending an applied decrement would double-count it.
     *
     * @param  list<StockAdjustment>  $adjustments
     * @return CommerceResult<InventoryAdjustReport>
     */
    public function adjust(PlatformConnection $connection, array $adjustments, int $chunkSize = 50): CommerceResult;

    /**
     * Read a variant's quantity via List Product Quantities
     * (GET /products/quantities, https://docs.salla.dev/api-9612796.md).
     *
     * @return CommerceResult<VariantQuantity>
     */
    public function variantQuantity(
        PlatformConnection $connection,
        string $variantId,
        ?int $branchId = null,
    ): CommerceResult;
}
