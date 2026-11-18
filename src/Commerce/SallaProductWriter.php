<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Catalog\StoreProduct;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla product writes (base https://api.salla.dev/admin/v2).
 *
 * POST /products → {data:{id,…}} (201); PUT /products/{product};
 * DELETE /products/{product} (202); PUT /products/variants/{variant};
 * POST /products/{product}/status {status}; POST /products/{product}/images.
 *
 * Category attach/replace has no dedicated endpoint in the docs, so
 * setCategories() replaces via PUT /products/{product} {categories:[…]}
 * (verified field on the update endpoint).
 *
 * The attach-image endpoint is documented as multipart/form-data with an
 * `original` URL field (https://docs.salla.dev/product-images/attach-image.md);
 * this implementation sends it as a JSON body, which the endpoint also
 * accepts for URL attachments — file uploads are out of scope.
 */
final class SallaProductWriter implements ProductWriter
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function create(PlatformConnection $connection, ProductInput $input): CommerceResult
    {
        $result = $this->gateway->post($connection, '/products', $input->toSallaArray());

        return CommerceResult::fromApiResult($result, fn ($data): ProductSummary => self::summary($data));
    }

    public function update(PlatformConnection $connection, string $productId, ProductPatch $patch): CommerceResult
    {
        $result = $this->gateway->put(
            $connection, '/products/'.rawurlencode($productId), $patch->toSallaArray()
        );

        return CommerceResult::fromApiResult($result, fn ($data): ProductSummary => self::summary($data));
    }

    public function delete(PlatformConnection $connection, string $productId): CommerceResult
    {
        $result = $this->gateway->delete($connection, '/products/'.rawurlencode($productId));

        return CommerceResult::fromApiResult($result, fn (): bool => true);
    }

    public function updateVariant(PlatformConnection $connection, string $variantId, ProductVariantPatch $patch): CommerceResult
    {
        $result = $this->gateway->put(
            $connection, '/products/variants/'.rawurlencode($variantId), $patch->toSallaArray()
        );

        return CommerceResult::fromApiResult($result, function ($data): VariantSummary {
            $row = is_array($data) ? $data : [];

            return new VariantSummary(
                id: (string) ($row['id'] ?? ''),
                sku: isset($row['sku']) && is_scalar($row['sku']) ? (string) $row['sku'] : null,
                stockQuantity: isset($row['stock_quantity']) && is_numeric($row['stock_quantity'])
                    ? (int) $row['stock_quantity']
                    : null,
                raw: $row,
            );
        });
    }

    public function setCategories(PlatformConnection $connection, string $productId, array $categoryIds): CommerceResult
    {
        $result = $this->gateway->put(
            $connection,
            '/products/'.rawurlencode($productId),
            ['categories' => array_values(array_map(intval(...), $categoryIds))],
        );

        return CommerceResult::fromApiResult($result, fn ($data): ProductSummary => self::summary($data));
    }

    public function addImageByUrl(
        PlatformConnection $connection,
        string $productId,
        string $imageUrl,
        bool $main = false,
        ?int $sort = null,
        ?string $alt = null,
    ): CommerceResult {
        $result = $this->gateway->post(
            $connection,
            '/products/'.rawurlencode($productId).'/images',
            array_filter([
                'original' => $imageUrl,
                'main' => $main,
                'sort' => $sort,
                'alt' => $alt,
            ], static fn ($v): bool => $v !== null),
        );

        return CommerceResult::fromApiResult($result, function ($data): ImageSummary {
            $row = is_array($data) ? $data : [];
            $image = is_array($row['image'] ?? null) ? $row['image'] : [];
            $original = is_array($image['original'] ?? null) ? $image['original'] : [];

            $url = $original['url'] ?? null;
            $url = is_string($url) && $url !== '' ? $url : null;

            return new ImageSummary(
                id: (string) ($row['id'] ?? ''),
                url: $url,
                raw: $row,
            );
        });
    }

    public function updateStatus(PlatformConnection $connection, string $productId, ProductStatus $status): CommerceResult
    {
        $result = $this->gateway->post(
            $connection,
            '/products/'.rawurlencode($productId).'/status',
            ['status' => $status->value],
        );

        return CommerceResult::fromApiResult($result, fn (): bool => true);
    }

    private static function summary(mixed $data): ProductSummary
    {
        $row = is_array($data) ? $data : [];

        $status = $row['status'] ?? null;

        return new ProductSummary(
            id: (string) ($row['id'] ?? ''),
            name: StoreProduct::preferArabic($row['name'] ?? null),
            status: is_string($status) ? $status : null,
            raw: $row,
        );
    }
}
