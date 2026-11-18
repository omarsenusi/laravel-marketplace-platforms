<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use InvalidArgumentException;
use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Catalog\StoreProduct;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla category writes + reads (base https://api.salla.dev/admin/v2).
 */
final class SallaCategoryWriter implements CategoryWriter
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function create(PlatformConnection $connection, CategoryInput $input): CommerceResult
    {
        if (trim($input->name) === '') {
            throw new InvalidArgumentException('Category requires a non-empty name.');
        }

        $result = $this->gateway->post($connection, '/categories', $input->toSallaArray());

        return CommerceResult::fromApiResult($result, fn ($data): Category => self::category($data));
    }

    public function find(PlatformConnection $connection, string $categoryId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/categories/'.rawurlencode($categoryId));

        return CommerceResult::fromApiResult($result, fn ($data): Category => self::category($data));
    }

    public function list(PlatformConnection $connection, int $page = 1, ?string $keyword = null, ?CategoryStatus $status = null): CommerceResult
    {
        $result = $this->gateway->get($connection, '/categories', array_filter([
            'page' => max(1, $page),
            'keyword' => $keyword !== null && trim($keyword) !== '' ? trim($keyword) : null,
            'status' => $status?->value,
        ], static fn ($v): bool => $v !== null));

        return CommerceResult::fromApiResult($result, function ($data, $raw = null): CategoryListPage {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return new CategoryListPage(
                categories: array_values(array_map(
                    static fn ($row): Category => self::category($row),
                    array_filter($rows, 'is_array'),
                )),
                nextPage: self::nextPage($raw),
                total: self::total($raw),
            );
        });
    }

    public function update(PlatformConnection $connection, string $categoryId, CategoryPatch $patch): CommerceResult
    {
        $result = $this->gateway->put(
            $connection, '/categories/'.rawurlencode($categoryId), $patch->toSallaArray()
        );

        return CommerceResult::fromApiResult($result, fn ($data): Category => self::category($data));
    }

    public function delete(PlatformConnection $connection, string $categoryId): CommerceResult
    {
        $result = $this->gateway->delete($connection, '/categories/'.rawurlencode($categoryId));

        return CommerceResult::fromApiResult($result, fn (): bool => true);
    }

    private static function category(mixed $data): Category
    {
        $row = is_array($data) ? $data : [];

        return new Category(
            id: (string) ($row['id'] ?? ''),
            name: StoreProduct::preferArabic($row['name'] ?? null),
            status: isset($row['status']) && is_string($row['status']) ? $row['status'] : null,
            image: isset($row['image']) && is_string($row['image']) ? $row['image'] : null,
            parentId: isset($row['parent_id']) && is_numeric($row['parent_id']) ? (int) $row['parent_id'] : null,
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
