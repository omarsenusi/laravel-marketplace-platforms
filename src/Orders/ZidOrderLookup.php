<?php

namespace Omarsenusi\MarketplacePlatforms\Orders;

use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Zid order lookup (base https://api.zid.sa/v1).
 *
 * GET /managers/store/orders?search_term={n}&page=1&per_page=10
 * (Accept-Language: ar) → `orders` array. The order whose `code` or `id`
 * equals the number wins. Tracking fields are read defensively from
 * `shipping` (tracking_number, tracking.number, tracking_url,
 * tracking.url, method.name) — a single shipment entry is built only
 * when at least one of them is present.
 */
final class ZidOrderLookup
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function lookup(PlatformConnection $connection, string $orderNumber, string $contact): OrderLookupOutcome
    {
        $result = $this->gateway->get(
            $connection, '/managers/store/orders',
            ['search_term' => $orderNumber, 'page' => 1, 'per_page' => 10],
            'realtime', 0, ['Accept-Language' => 'ar'],
        );

        if ($result->deferred || ! $result->ok || ! is_array($result->json)) {
            return new OrderLookupOutcome(OrderLookup::temporary());
        }

        $rows = $result->json['orders'] ?? null;
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

        $order = null;
        foreach ($rows as $row) {
            $code = $row['code'] ?? null;
            $id = $row['id'] ?? null;

            if ((is_string($code) || is_numeric($code)) && trim((string) $code) === $orderNumber) {
                $order = $row;
                break;
            }

            if ((is_string($id) || is_numeric($id)) && trim((string) $id) === $orderNumber) {
                $order = $row;
                break;
            }
        }

        if ($order === null) {
            return new OrderLookupOutcome(OrderLookup::notFound(), true);
        }

        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];

        if (! OrderContact::matches($contact, $customer['email'] ?? null, $customer['phone'] ?? null)) {
            return new OrderLookupOutcome(OrderLookup::notFound(), true);
        }

        return new OrderLookupOutcome($this->payload($orderNumber, $order));
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function payload(string $orderNumber, array $order): array
    {
        $rawTotal = $order['order_total'] ?? null;
        $total = is_numeric($rawTotal) ? (float) $rawTotal : self::parseTotal($order['order_total_string'] ?? null);

        $rawItems = $order['products'] ?? $order['items'] ?? [];
        $rawItems = is_array($rawItems) ? array_values(array_filter($rawItems, 'is_array')) : [];

        $items = [];
        foreach (array_slice($rawItems, 0, 5) as $item) {
            $name = $item['name'] ?? null;
            if (is_array($name)) {
                $name = $name['ar'] ?? $name['en'] ?? (is_string(reset($name)) ? reset($name) : null);
            }

            $items[] = [
                'name' => is_string($name) ? $name : null,
                'quantity' => isset($item['quantity']) && is_numeric($item['quantity']) ? (int) $item['quantity'] : null,
            ];
        }

        return [
            'found' => true,
            'order_number' => (string) ($order['code'] ?? $order['id'] ?? $orderNumber),
            'status' => self::statusName($order['order_status'] ?? null),
            'date' => is_string($order['created_at'] ?? null) ? $order['created_at'] : null,
            'total' => $total,
            'currency' => $order['currency_code'] ?? null,
            'items' => $items,
            'shipments' => self::shipments($order),
            'order_url' => $order['order_url'] ?? null,
        ];
    }

    private static function statusName(mixed $status): ?string
    {
        if (is_string($status)) {
            return trim($status) !== '' ? $status : null;
        }

        if (! is_array($status)) {
            return null;
        }

        foreach (['name', 'name_ar', 'ar_name', 'ar', 'title', 'label', 'slug', 'status'] as $key) {
            $value = $status[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private static function shipments(array $order): array
    {
        $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
        $tracking = is_array($shipping['tracking'] ?? null) ? $shipping['tracking'] : [];
        $method = is_array($shipping['method'] ?? null) ? $shipping['method'] : [];

        $number = $shipping['tracking_number'] ?? $tracking['number'] ?? null;
        $url = $shipping['tracking_url'] ?? $tracking['url'] ?? null;
        $courier = $method['name'] ?? $shipping['courier'] ?? null;

        $number = is_string($number) && trim($number) !== '' ? $number : null;
        $url = is_string($url) && trim($url) !== '' ? $url : null;
        $courier = is_string($courier) && trim($courier) !== '' ? $courier : null;

        if ($number === null && $url === null && $courier === null) {
            return [];
        }

        return [[
            'status' => null,
            'courier' => $courier,
            'tracking_number' => $number,
            'tracking_url' => $url,
        ]];
    }

    private static function parseTotal(mixed $value): ?float
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (preg_match('/[\d,]+\.?\d*/', str_replace(',', '', $value), $m) === 1) {
            return is_numeric($m[0]) ? (float) $m[0] : null;
        }

        return null;
    }
}
