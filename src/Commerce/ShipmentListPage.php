<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  list<Shipment>  $shipments
 */
final readonly class ShipmentListPage
{
    public function __construct(
        public array $shipments,
        public ?int $nextPage = null,
        public ?int $total = null,
    ) {}
}
