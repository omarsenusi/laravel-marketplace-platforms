<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * One stock change for POST /products/quantities/bulk.
 *
 * The mode is required: callers syncing live stock must choose it
 * explicitly. Prefer increment/decrement deltas (concurrency-safe —
 * Salla applies each delta atomically); overwrite only for an initial
 * load or a full reconciliation, since it can clobber concurrent sales.
 *
 * Salla spells the identifier fields `identifer_type` / `identifer`
 * (verified against https://docs.salla.dev/api-5394192.md) — the DTO
 * keeps clean names and the Salla implementation maps them at the edge.
 */
final readonly class StockAdjustment
{
    public function __construct(
        public StockIdentifierType $identifierType,
        public string $identifier,
        public int $quantity,
        public StockMode $mode,
        public ?int $branchId = null,
        public ?int $reasonId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'identifer_type' => $this->identifierType->value,
            'identifer' => $this->identifier,
            'quantity' => $this->quantity,
            'mode' => $this->mode->value,
            'branch' => $this->branchId,
            'reason_id' => $this->reasonId,
        ], static fn ($v): bool => $v !== null);
    }
}
