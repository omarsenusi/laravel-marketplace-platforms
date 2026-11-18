<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Store category writes plus targeted reads (Salla Admin API).
 *
 * Used for the hidden platform-products category among other things.
 * Verified against https://docs.salla.dev/categories/create.md,
 * .../details.md, .../list.md, .../update.md and .../delete.md.
 */
interface CategoryWriter
{
    /**
     * @return CommerceResult<Category>
     */
    public function create(PlatformConnection $connection, CategoryInput $input): CommerceResult;

    /**
     * @return CommerceResult<Category>
     */
    public function find(PlatformConnection $connection, string $categoryId): CommerceResult;

    /**
     * @return CommerceResult<CategoryListPage>
     */
    public function list(PlatformConnection $connection, int $page = 1, ?string $keyword = null, ?CategoryStatus $status = null): CommerceResult;

    /**
     * @return CommerceResult<Category>
     */
    public function update(PlatformConnection $connection, string $categoryId, CategoryPatch $patch): CommerceResult;

    /**
     * @return CommerceResult<true>
     */
    public function delete(PlatformConnection $connection, string $categoryId): CommerceResult;
}
