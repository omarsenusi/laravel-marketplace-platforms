<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use InvalidArgumentException;
use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla inventory writes (base https://api.salla.dev/admin/v2).
 *
 * POST /products/quantities/bulk accepts up to large lists; this
 * implementation chunks them (default 50 per request) so a huge sync
 * never builds one giant body. Reads go through GET /products/quantities
 * (branch filter + pagination) and scan pages for the variant.
 */
final class SallaInventoryWriter implements InventoryWriter
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function adjust(PlatformConnection $connection, array $adjustments, int $chunkSize = 50): CommerceResult
    {
        foreach ($adjustments as $adjustment) {
            if (! $adjustment instanceof StockAdjustment) {
                throw new InvalidArgumentException('adjust() expects a list of StockAdjustment DTOs.');
            }
        }

        $chunkSize = max(1, $chunkSize);
        $chunks = array_chunk($adjustments, $chunkSize);

        if ($chunks === []) {
            return CommerceResult::ok(new InventoryAdjustReport(true, 0, 0));
        }

        $raw = [];
        $message = null;
        $appliedChunks = 0;
        $appliedItems = 0;

        foreach ($chunks as $index => $chunk) {
            $result = $this->gateway->post(
                $connection,
                '/products/quantities/bulk',
                ['products' => array_map(static fn (StockAdjustment $a): array => $a->toSallaArray(), $chunk)],
            );

            $mapped = CommerceResult::fromApiResult($result, static function ($data): ?string {
                $row = is_array($data) ? $data : [];

                return isset($row['message']) && is_string($row['message']) ? $row['message'] : null;
            });

            if (! $mapped->ok) {
                // Stop at the first failed chunk: earlier chunks were
                // already queued by Salla, so report what applied and hand
                // back everything not applied (failed chunk first) for a
                // targeted retry. CommerceResult::fail carries no data,
                // hence the direct construction.
                $pending = [];

                foreach (array_slice($chunks, $index) as $rest) {
                    foreach ($rest as $adjustment) {
                        $pending[] = $adjustment;
                    }
                }

                return new CommerceResult(
                    ok: false,
                    status: $mapped->status,
                    data: new InventoryAdjustReport(false, $appliedChunks, $appliedItems, null, $raw, $pending),
                    error: $mapped->error,
                    errorCode: $mapped->errorCode,
                    fields: $mapped->fields,
                    forbidden: $mapped->status === 403,
                    raw: $mapped->raw,
                    rateLimitRemaining: $mapped->rateLimitRemaining,
                    retryAfterHeader: $mapped->retryAfterHeader,
                );
            }

            $appliedChunks++;
            $appliedItems += count($chunk);
            $raw[] = $mapped->raw ?? [];
            $message ??= is_string($mapped->data) ? $mapped->data : null;
        }

        return CommerceResult::ok(new InventoryAdjustReport(true, $appliedChunks, $appliedItems, $message, $raw));
    }

    public function variantQuantity(
        PlatformConnection $connection,
        string $variantId,
        ?int $branchId = null,
    ): CommerceResult {
        $variantId = trim($variantId);

        if ($variantId === '') {
            throw new InvalidArgumentException('variantQuantity() requires a non-empty variant id.');
        }

        $page = 1;

        // The list endpoint has no direct variant filter, so pages are
        // scanned for the matching sku_id/sku. Bounded at 20 pages so a
        // missing variant cannot page through a whole catalog.
        while ($page <= 20) {
            $query = array_filter(['page' => $page, 'branch' => $branchId], static fn ($v): bool => $v !== null);
            $result = $this->gateway->get($connection, '/products/quantities', $query);

            $mapped = CommerceResult::fromApiResult($result, static function ($data): array {
                $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

                return array_values(array_filter($rows, 'is_array'));
            });

            if (! $mapped->ok) {
                return CommerceResult::fail(
                    $mapped->status, $mapped->error, $mapped->errorCode, $mapped->fields, $mapped->raw,
                    $mapped->rateLimitRemaining, $mapped->retryAfterHeader,
                );
            }

            foreach ($mapped->data as $row) {
                $skuId = isset($row['sku_id']) && is_scalar($row['sku_id']) ? (string) $row['sku_id'] : null;
                $sku = isset($row['sku']) && is_scalar($row['sku']) ? (string) $row['sku'] : null;

                if ($skuId === $variantId || $sku === $variantId) {
                    return CommerceResult::ok(self::quantity($row), $mapped->status, $mapped->raw);
                }
            }

            if (! self::hasNextPage($mapped->raw)) {
                break;
            }

            $page++;
        }

        return CommerceResult::fail(404, 'http_404', 'not_found');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function quantity(array $row): VariantQuantity
    {
        return new VariantQuantity(
            productId: (string) ($row['id'] ?? ''),
            variantId: isset($row['sku_id']) && is_scalar($row['sku_id']) ? (string) $row['sku_id'] : null,
            sku: isset($row['sku']) && is_scalar($row['sku']) ? (string) $row['sku'] : null,
            quantity: isset($row['quantity']) && is_numeric($row['quantity']) ? (int) $row['quantity'] : null,
            unlimited: ($row['unlimited_quantity'] ?? false) === true,
            soldQuantity: isset($row['sold_quantity']) && is_numeric($row['sold_quantity'])
                ? (int) $row['sold_quantity']
                : null,
            raw: $row,
        );
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function hasNextPage(?array $body): bool
    {
        $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
        $current = $pagination['currentPage'] ?? $pagination['current_page'] ?? $pagination['current'] ?? null;
        $total = $pagination['totalPages'] ?? $pagination['total_pages'] ?? $pagination['pages'] ?? null;

        if (is_numeric($current) && is_numeric($total)) {
            return (int) $current < (int) $total;
        }

        foreach (['next', 'next_page'] as $key) {
            $next = $pagination[$key] ?? null;

            if ($next === true) {
                return true;
            }

            if (is_numeric($next)) {
                return true;
            }

            if (is_string($next) && trim($next) !== '') {
                return true;
            }
        }

        if (is_array($pagination['links'] ?? null)) {
            $nextLink = $pagination['links']['next'] ?? null;

            return is_string($nextLink) && trim($nextLink) !== '';
        }

        return false;
    }
}
