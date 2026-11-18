<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Branch origin for ship_from ({type: branch, branch_id}).
 */
final readonly class ShipFromBranch
{
    public function __construct(public int $branchId) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return ['type' => 'branch', 'branch_id' => $this->branchId];
    }
}
