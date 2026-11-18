<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use DateTimeInterface;
use InvalidArgumentException;
use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla order writes + targeted reads (base https://api.salla.dev/admin/v2).
 */
final class SallaOrderWriter implements OrderWriter
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function updateStatus(
        PlatformConnection $connection,
        string $orderId,
        ?string $slug = null,
        ?int $statusId = null,
        ?string $note = null,
        ?bool $restoreItems = null,
        ?bool $sendStatusSms = null,
    ): CommerceResult {
        if (($slug === null || trim($slug) === '') && $statusId === null) {
            throw new InvalidArgumentException('Order status update requires a slug or a status id.');
        }

        $result = $this->gateway->post(
            $connection,
            '/orders/'.rawurlencode($orderId).'/status',
            array_filter([
                'slug' => $slug !== null && trim($slug) !== '' ? trim($slug) : null,
                'status_id' => $statusId,
                'note' => $note,
                'restore_items' => $restoreItems,
                'send_status_sms' => $sendStatusSms,
            ], static fn ($v): bool => $v !== null),
        );

        return CommerceResult::fromApiResult($result, function ($data): OrderStatusUpdate {
            $row = is_array($data) ? $data : [];

            return new OrderStatusUpdate(
                message: isset($row['message']) && is_string($row['message']) ? $row['message'] : null,
                raw: $row,
            );
        });
    }

    public function listStatuses(PlatformConnection $connection): CommerceResult
    {
        $result = $this->gateway->get($connection, '/orders/statuses');

        return CommerceResult::fromApiResult($result, function ($data): array {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return array_values(array_map(
                static fn ($row): OrderStatus => self::status($row),
                array_filter($rows, 'is_array'),
            ));
        });
    }

    public function createCustomStatus(
        PlatformConnection $connection,
        int $parentId,
        string $name,
        ?string $message = null,
        ?string $icon = null,
        ?int $sort = null,
        ?bool $isActive = null,
    ): CommerceResult {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Custom order status requires a non-empty name.');
        }

        $result = $this->gateway->post($connection, '/orders/statuses', array_filter([
            'parent_id' => $parentId,
            'name' => trim($name),
            'message' => $message,
            'icon' => $icon,
            'sort' => $sort,
            'is_active' => $isActive,
        ], static fn ($v): bool => $v !== null));

        return CommerceResult::fromApiResult($result, fn ($data): OrderStatus => self::status($data));
    }

    public function find(PlatformConnection $connection, string $orderId): CommerceResult
    {
        $result = $this->gateway->get(
            $connection, '/orders/'.rawurlencode($orderId), ['format' => 'light']
        );

        return CommerceResult::fromApiResult($result, fn ($data): OrderSummary => self::summary($data));
    }

    public function listSince(
        PlatformConnection $connection,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int $page = 1,
        string $sortBy = 'updated_at-desc',
    ): CommerceResult {
        $query = array_filter([
            'from_date' => $since->format('Y-m-d'),
            'to_date' => $until?->format('Y-m-d'),
            'sort_by' => $sortBy,
            'page' => max(1, $page),
        ], static fn ($v): bool => $v !== null);

        $result = $this->gateway->get($connection, '/orders', $query);

        return CommerceResult::fromApiResult($result, function ($data, $raw = null): OrderListPage {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return new OrderListPage(
                orders: array_values(array_map(
                    static fn ($row): OrderSummary => self::summary($row),
                    array_filter($rows, 'is_array'),
                )),
                nextPage: self::nextPage($raw),
                total: self::total($raw),
            );
        });
    }

    public function listItems(PlatformConnection $connection, string $orderId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/orders/items', ['order_id' => $orderId]);

        return CommerceResult::fromApiResult($result, function ($data): array {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return array_values(array_map(static function ($row): OrderItem {
                $row = is_array($row) ? $row : [];
                $amounts = is_array($row['amounts'] ?? null) ? $row['amounts'] : [];

                return new OrderItem(
                    id: (string) ($row['id'] ?? ''),
                    name: isset($row['name']) && is_string($row['name']) ? $row['name'] : null,
                    sku: isset($row['sku']) && is_scalar($row['sku']) ? (string) $row['sku'] : null,
                    quantity: isset($row['quantity']) && is_numeric($row['quantity']) ? (int) $row['quantity'] : null,
                    price: SallaMoney::fromRow($amounts, 'total'),
                    raw: $row,
                );
            }, array_filter($rows, 'is_array')));
        });
    }

    private static function status(mixed $data): OrderStatus
    {
        $row = is_array($data) ? $data : [];

        return new OrderStatus(
            id: (string) ($row['id'] ?? ''),
            name: isset($row['name']) && is_string($row['name']) ? $row['name'] : null,
            slug: isset($row['slug']) && is_string($row['slug']) ? $row['slug'] : null,
            type: isset($row['type']) && is_string($row['type']) ? $row['type'] : null,
            message: isset($row['message']) && is_string($row['message']) ? $row['message'] : null,
            icon: isset($row['icon']) && is_string($row['icon']) ? $row['icon'] : null,
            isActive: array_key_exists('is_active', $row) ? (bool) $row['is_active'] : null,
            raw: $row,
        );
    }

    private static function summary(mixed $data): OrderSummary
    {
        $row = is_array($data) ? $data : [];
        $status = is_array($row['status'] ?? null) ? $row['status'] : [];
        $total = is_array($row['total'] ?? null) ? $row['total'] : [];
        $amounts = is_array($row['amounts'] ?? null) ? $row['amounts'] : [];

        return new OrderSummary(
            id: (string) ($row['id'] ?? ''),
            referenceId: isset($row['reference_id']) && is_numeric($row['reference_id'])
                ? (int) $row['reference_id']
                : null,
            statusName: isset($status['name']) && is_string($status['name']) ? $status['name'] : null,
            statusSlug: isset($status['slug']) && is_string($status['slug']) ? $status['slug'] : null,
            total: SallaMoney::fromRow($total, 'amount') ?? SallaMoney::fromRow($amounts, 'total'),
            raw: $row,
        );
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function nextPage(?array $body): ?int
    {
        $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
        $current = $pagination['currentPage'] ?? $pagination['current_page'] ?? $pagination['current'] ?? null;
        $total = $pagination['totalPages'] ?? $pagination['total_pages'] ?? $pagination['pages'] ?? null;

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
        $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : null;
        $total = is_array($pagination) ? ($pagination['total'] ?? null) : ($body['total'] ?? null);

        return is_numeric($total) ? (int) $total : null;
    }
}
