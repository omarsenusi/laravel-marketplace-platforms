<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla shipment writes (base https://api.salla.dev/admin/v2).
 */
final class SallaShipmentWriter implements ShipmentWriter
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function create(PlatformConnection $connection, ShipmentInput $input): CommerceResult
    {
        $result = $this->gateway->post($connection, '/shipments', $input->toSallaArray());

        return CommerceResult::fromApiResult($result, fn ($data): Shipment => self::shipment($data));
    }

    public function update(PlatformConnection $connection, string $shipmentId, ShipmentUpdate $update): CommerceResult
    {
        $result = $this->gateway->put(
            $connection, '/shipments/'.rawurlencode($shipmentId), $update->toSallaArray()
        );

        return CommerceResult::fromApiResult($result, fn ($data): Shipment => self::shipment($data));
    }

    public function find(PlatformConnection $connection, string $shipmentId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipments/'.rawurlencode($shipmentId));

        return CommerceResult::fromApiResult($result, fn ($data): Shipment => self::shipment($data));
    }

    public function listForOrder(PlatformConnection $connection, int $orderId, int $page = 1, int $perPage = 15): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipments', [
            'order_id' => $orderId,
            'page' => max(1, $page),
            'per_page' => max(1, min(60, $perPage)),
        ]);

        return CommerceResult::fromApiResult($result, function ($data, $raw = null): ShipmentListPage {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return new ShipmentListPage(
                shipments: array_values(array_map(
                    static fn ($row): Shipment => self::shipment($row),
                    array_filter($rows, 'is_array'),
                )),
                nextPage: self::nextPage($raw),
                total: self::total($raw),
            );
        });
    }

    public function cancel(PlatformConnection $connection, string $shipmentId): CommerceResult
    {
        $result = $this->gateway->post($connection, '/shipments/'.rawurlencode($shipmentId).'/cancel');

        return CommerceResult::fromApiResult($result, fn ($data): Shipment => self::shipment($data));
    }

    public function returnShipment(PlatformConnection $connection, string $shipmentId): CommerceResult
    {
        $result = $this->gateway->post($connection, '/shipments/'.rawurlencode($shipmentId).'/return');

        return CommerceResult::fromApiResult($result, fn ($data): Shipment => self::shipment($data));
    }

    public function tracking(PlatformConnection $connection, string $shipmentId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipments/'.rawurlencode($shipmentId).'/tracking');

        return CommerceResult::fromApiResult($result, function ($data): ShipmentTracking {
            $row = is_array($data) ? $data : [];
            $history = is_array($row['history'] ?? null) ? $row['history'] : [];

            return new ShipmentTracking(
                shipmentId: (string) ($row['id'] ?? ''),
                status: isset($row['status']) && is_string($row['status']) ? $row['status'] : null,
                trackingNumber: self::scalar($row['tracking_number'] ?? null),
                trackingLink: self::scalar($row['tracking_link'] ?? null),
                history: array_values(array_map(static function ($event): ShipmentTrackingEvent {
                    $event = is_array($event) ? $event : [];

                    return new ShipmentTrackingEvent(
                        status: isset($event['status']) && is_string($event['status']) ? $event['status'] : null,
                        note: isset($event['note']) && is_string($event['note']) ? $event['note'] : null,
                        createdAt: self::eventDate($event['create_at'] ?? null),
                        raw: $event,
                    );
                }, array_filter($history, 'is_array'))),
                raw: $row,
            );
        });
    }

    private static function shipment(mixed $data): Shipment
    {
        $row = is_array($data) ? $data : [];

        return new Shipment(
            id: (string) ($row['id'] ?? ''),
            orderId: isset($row['order_id']) && is_numeric($row['order_id']) ? (int) $row['order_id'] : null,
            orderReferenceId: isset($row['order_reference_id']) && is_numeric($row['order_reference_id'])
                ? (int) $row['order_reference_id']
                : null,
            type: isset($row['type']) && is_string($row['type']) ? $row['type'] : null,
            status: isset($row['status']) && is_string($row['status']) ? $row['status'] : null,
            courierName: isset($row['courier_name']) && is_string($row['courier_name']) ? $row['courier_name'] : null,
            trackingNumber: self::scalar($row['tracking_number'] ?? null),
            trackingLink: self::scalar($row['tracking_link'] ?? null),
            shippingNumber: self::scalar($row['shipping_number'] ?? null),
            cashOnDelivery: SallaMoney::fromRow($row, 'cash_on_delivery'),
            raw: $row,
        );
    }

    private static function scalar(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function eventDate(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_array($value) && isset($value['date']) && is_string($value['date'])) {
            return trim($value['date']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function nextPage(?array $body): ?int
    {
        $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
        $current = $pagination['currentPage'] ?? $pagination['current_page'] ?? null;
        $total = $pagination['totalPages'] ?? $pagination['total_pages'] ?? null;

        if (is_numeric($current) && is_numeric($total) && (int) $current < (int) $total) {
            return (int) $current + 1;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function total(?array $body): ?int
    {
        $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
        $total = $pagination['total'] ?? null;

        return is_numeric($total) ? (int) $total : null;
    }
}
