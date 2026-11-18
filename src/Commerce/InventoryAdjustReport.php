<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Outcome of a bulk stock adjust. Salla queues bulk updates, so success
 * means every chunk was accepted for processing, with the queue message.
 *
 * Counts are APPLIED work: on a partial failure $accepted is false and
 * $pending holds the not-applied adjustments (failed chunk plus all
 * later ones, in order) — retry only those, never the whole list.
 *
 * @param  list<StockAdjustment>  $pending
 * @param  list<array<string, mixed>>  $raw
 */
final readonly class InventoryAdjustReport
{
    public function __construct(
        public bool $accepted,
        public int $chunkCount,
        public int $adjustmentCount,
        public ?string $message = null,
        public array $raw = [],
        public array $pending = [],
    ) {}
}
