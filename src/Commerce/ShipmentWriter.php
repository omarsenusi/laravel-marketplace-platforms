<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Create and manage store shipments (Salla Admin API).
 *
 * Verified against https://docs.salla.dev/shipments/create.md,
 * .../update.md, .../details.md, .../list.md, .../cancel.md,
 * .../return.md and .../track.md. Results never throw for HTTP
 * failures — see CommerceResult.
 */
interface ShipmentWriter
{
    /**
     * @return CommerceResult<Shipment>
     */
    public function create(PlatformConnection $connection, ShipmentInput $input): CommerceResult;

    /**
     * @return CommerceResult<Shipment>
     */
    public function update(PlatformConnection $connection, string $shipmentId, ShipmentUpdate $update): CommerceResult;

    /**
     * @return CommerceResult<Shipment>
     */
    public function find(PlatformConnection $connection, string $shipmentId): CommerceResult;

    /**
     * @return CommerceResult<ShipmentListPage>
     */
    public function listForOrder(PlatformConnection $connection, int $orderId, int $page = 1, int $perPage = 15): CommerceResult;

    /**
     * @return CommerceResult<Shipment>
     */
    public function cancel(PlatformConnection $connection, string $shipmentId): CommerceResult;

    /**
     * @return CommerceResult<Shipment>
     */
    public function returnShipment(PlatformConnection $connection, string $shipmentId): CommerceResult;

    /**
     * @return CommerceResult<ShipmentTracking>
     */
    public function tracking(PlatformConnection $connection, string $shipmentId): CommerceResult;
}
