<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use DateTimeInterface;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Order status writes plus targeted order reads (Salla Admin API).
 *
 * Status updates go over POST /orders/{order_id}/status with either a
 * status `slug` or a numeric `status_id` (verified against
 * https://docs.salla.dev/order-status/update-order-status.md); custom
 * statuses are created via POST /orders/statuses
 * (https://docs.salla.dev/order-status/create-custom-order-status.md).
 * Results never throw for HTTP failures — see CommerceResult.
 */
interface OrderWriter
{
    /**
     * @return CommerceResult<OrderStatusUpdate>
     */
    public function updateStatus(
        PlatformConnection $connection,
        string $orderId,
        ?string $slug = null,
        ?int $statusId = null,
        ?string $note = null,
        ?bool $restoreItems = null,
        ?bool $sendStatusSms = null,
    ): CommerceResult;

    /**
     * @return CommerceResult<list<OrderStatus>>
     */
    public function listStatuses(PlatformConnection $connection): CommerceResult;

    /**
     * @return CommerceResult<OrderStatus>
     */
    public function createCustomStatus(
        PlatformConnection $connection,
        int $parentId,
        string $name,
        ?string $message = null,
        ?string $icon = null,
        ?int $sort = null,
        ?bool $isActive = null,
    ): CommerceResult;

    /**
     * @return CommerceResult<OrderSummary>
     */
    public function find(PlatformConnection $connection, string $orderId): CommerceResult;

    /**
     * Orders created in [$since, $until] for reconciliation polling.
     *
     * Salla documents no updated-since filter; from_date/to_date filter
     * by CREATION date (yyyy-mm-dd) and sort_by supports updated_at, so
     * polling combines from_date with sort_by=updated_at-desc and pages
     * through. Verified against https://docs.salla.dev/orders/list.md.
     *
     * @return CommerceResult<OrderListPage>
     */
    public function listSince(
        PlatformConnection $connection,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
        int $page = 1,
        string $sortBy = 'updated_at-desc',
    ): CommerceResult;

    /**
     * @return CommerceResult<list<OrderItem>>
     */
    public function listItems(PlatformConnection $connection, string $orderId): CommerceResult;
}
