<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Sparse variant patch for PUT /products/variants/{variant}. At least one
 * field must be set (Salla rejects an empty body with 422).
 * Verified against https://docs.salla.dev/product-variants/update.md.
 */
final readonly class ProductVariantPatch
{
    public function __construct(
        public ?Money $price = null,
        public ?Money $salePrice = null,
        public ?string $sku = null,
        public ?string $barcode = null,
        public ?int $stockQuantity = null,
        public ?float $weight = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'price' => $this->price === null ? null : (float) SallaMoney::toDecimal($this->price->amountMinor),
            'sale_price' => $this->salePrice === null ? null : (float) SallaMoney::toDecimal($this->salePrice->amountMinor),
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'stock_quantity' => $this->stockQuantity,
            'weight' => $this->weight,
        ], static fn ($v): bool => $v !== null);
    }
}
