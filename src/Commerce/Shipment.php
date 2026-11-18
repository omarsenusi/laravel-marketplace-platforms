<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class Shipment
{
    public function __construct(
        public string $id,
        public ?int $orderId = null,
        public ?int $orderReferenceId = null,
        public ?string $type = null,
        public ?string $status = null,
        public ?string $courierName = null,
        public ?string $trackingNumber = null,
        public ?string $trackingLink = null,
        public ?string $shippingNumber = null,
        public ?Money $cashOnDelivery = null,
        public array $raw = [],
    ) {}
}
