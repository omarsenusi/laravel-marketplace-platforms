<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Shipment patch for PUT /shipments/{shipment_id}.
 *
 * $status is required: since the 2.10.37 changelog Salla rejects update
 * calls without it. Verified against
 * https://docs.salla.dev/shipments/update.md.
 */
final readonly class ShipmentUpdate
{
    public function __construct(
        public string $status,
        public ?string $trackingNumber = null,
        public ?string $trackingLink = null,
        public ?string $shipmentNumber = null,
        public ?string $shippingNumber = null,
        public ?string $labelUrl = null,
        public ?string $statusNote = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'tracking_number' => $this->trackingNumber,
            'tracking_link' => $this->trackingLink,
            'shipment_number' => $this->shipmentNumber,
            'shipping_number' => $this->shippingNumber,
            'pdf_label' => $this->labelUrl,
            'status_note' => $this->statusNote,
        ], static fn ($v): bool => $v !== null);
    }
}
