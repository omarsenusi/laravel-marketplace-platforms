<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Push products into the store catalog (Salla Admin API).
 *
 * Money travels in minor units on every DTO; the Salla implementation
 * converts to decimal amounts (2 dp) at the HTTP edge and back on read.
 * Results never throw for HTTP failures — see CommerceResult.
 */
interface ProductWriter
{
    /**
     * @return CommerceResult<ProductSummary>
     */
    public function create(PlatformConnection $connection, ProductInput $input): CommerceResult;

    /**
     * @return CommerceResult<ProductSummary>
     */
    public function update(PlatformConnection $connection, string $productId, ProductPatch $patch): CommerceResult;

    /**
     * @return CommerceResult<true>
     */
    public function delete(PlatformConnection $connection, string $productId): CommerceResult;

    /**
     * @return CommerceResult<VariantSummary>
     */
    public function updateVariant(PlatformConnection $connection, string $variantId, ProductVariantPatch $patch): CommerceResult;

    /**
     * Replace the product's category attachments.
     *
     * @param  list<int>  $categoryIds
     * @return CommerceResult<ProductSummary>
     */
    public function setCategories(PlatformConnection $connection, string $productId, array $categoryIds): CommerceResult;

    /**
     * @return CommerceResult<ImageSummary>
     */
    public function addImageByUrl(
        PlatformConnection $connection,
        string $productId,
        string $imageUrl,
        bool $main = false,
        ?int $sort = null,
        ?string $alt = null,
    ): CommerceResult;

    /**
     * @return CommerceResult<true>
     */
    public function updateStatus(PlatformConnection $connection, string $productId, ProductStatus $status): CommerceResult;
}
