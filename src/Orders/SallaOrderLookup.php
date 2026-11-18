<?php

namespace Omarsenusi\MarketplacePlatforms\Orders;

use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla order lookup (base https://api.salla.dev/admin/v2).
 *
 * GET /orders?reference_id={n} (realtime lane, no cache) → data[] rows with
 * id, reference_id, urls{customer}, total{amount,currency}, date{date},
 * status{name,slug,customized{name}}, customer{mobile,mobile_code,email},
 * items[{name,quantity}]. Then GET /shipments?order_id={id} → data[] with
 * status, tracking_number, tracking_link|tracking_url, courier_name,
 * shipping_number. The shipments call is best-effort: a deferred/failed
 * response yields an empty shipments list, never a temporary failure.
 */
final class SallaOrderLookup
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function lookup(PlatformConnection $connection, string $orderNumber, string $contact): OrderLookupOutcome
    {
        $result = $this->gateway->get(
            $connection, '/orders',
            ['reference_id' => $orderNumber],
            'realtime',
        );

        if ($result->deferred || ! $result->ok || ! is_array($result->json)) {
            return new OrderLookupOutcome(OrderLookup::temporary());
        }

        $rows = $result->json['data'] ?? null;
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

        $order = null;
        foreach ($rows as $row) {
            $ref = $row['reference_id'] ?? null;
            if ((is_string($ref) || is_numeric($ref)) && trim((string) $ref) === $orderNumber) {
                $order = $row;
                break;
            }
        }

        if ($order === null) {
            return new OrderLookupOutcome(OrderLookup::notFound(), true);
        }

        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $phone = OrderContact::phoneDigits($customer['mobile_code'] ?? null, $customer['mobile'] ?? null);
        $email = $customer['email'] ?? null;

        if (! OrderContact::matches($contact, $email, $phone)) {
            return new OrderLookupOutcome(OrderLookup::notFound(), true);
        }

        return new OrderLookupOutcome($this->payload($connection, $orderNumber, $order));
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function payload(PlatformConnection $connection, string $orderNumber, array $order): array
    {
        $status = is_array($order['status'] ?? null) ? $order['status'] : [];
        $customized = $status['customized'] ?? null;

        $total = is_array($order['total'] ?? null) ? $order['total'] : [];
        $amounts = is_array($order['amounts'] ?? null) ? $order['amounts'] : [];
        $urls = is_array($order['urls'] ?? null) ? $order['urls'] : [];
        $date = is_array($order['date'] ?? null) ? $order['date'] : [];

        $amount = $total['amount'] ?? $amounts['total'] ?? null;

        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $items = array_values(array_filter($items, 'is_array'));

        return [
            'found' => true,
            'order_number' => (string) ($order['reference_id'] ?? $orderNumber),
            'status' => is_array($customized)
                ? ($customized['name'] ?? $status['name'] ?? $status['slug'] ?? null)
                : (is_string($customized) && trim($customized) !== '' ? $customized : ($status['name'] ?? $status['slug'] ?? null)),
            'date' => $date['date'] ?? (is_string($order['date'] ?? null) ? $order['date'] : null),
            'total' => is_numeric($amount) ? (float) $amount : null,
            'currency' => $total['currency'] ?? null,
            'items' => array_map(
                fn (array $item): array => [
                    'name' => is_string($item['name'] ?? null) ? $item['name'] : null,
                    'quantity' => isset($item['quantity']) && is_numeric($item['quantity']) ? (int) $item['quantity'] : null,
                ],
                array_slice($items, 0, 5),
            ),
            'shipments' => $this->shipments($connection, $order),
            'order_url' => $urls['customer'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private function shipments(PlatformConnection $connection, array $order): array
    {
        $orderId = $order['id'] ?? null;

        if (! is_string($orderId) && ! is_numeric($orderId)) {
            return [];
        }

        try {
            $result = $this->gateway->get(
                $connection, '/shipments',
                ['order_id' => $orderId],
                'realtime',
            );
        } catch (\Throwable) {
            return [];
        }

        if ($result->deferred || ! $result->ok || ! is_array($result->json)) {
            return [];
        }

        $rows = $result->json['data'] ?? null;
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'status' => $row['status'] ?? null,
                'courier' => $row['courier_name'] ?? null,
                'tracking_number' => $row['tracking_number'] ?? $row['shipping_number'] ?? null,
                'tracking_url' => $row['tracking_link'] ?? $row['tracking_url'] ?? null,
            ];
        }

        return $out;
    }
}
