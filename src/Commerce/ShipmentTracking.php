<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  list<ShipmentTrackingEvent>  $history
 * @param  array<string, mixed>  $raw
 */
final readonly class ShipmentTracking
{
    public function __construct(
        public string $shipmentId,
        public ?string $status = null,
        public ?string $trackingNumber = null,
        public ?string $trackingLink = null,
        public array $history = [],
        public array $raw = [],
    ) {}
}
