<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * New-product payload for POST /products.
 * Verified against https://docs.salla.dev/product/create-product.md.
 */
final readonly class ProductInput
{
    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public string $name,
        public Money $price,
        public string $productType = 'product',
        public ?int $quantity = null,
        public ?bool $unlimitedQuantity = null,
        public ?string $description = null,
        public array $categoryIds = [],
        public ?Money $salePrice = null,
        public ?string $sku = null,
        public ?bool $requireShipping = null,
        public ?float $weight = null,
        public ?string $weightType = null,
        public ?ProductStatus $status = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'price' => (float) SallaMoney::toDecimal($this->price->amountMinor),
            'product_type' => $this->productType,
            'quantity' => $this->quantity,
            'unlimited_quantity' => $this->unlimitedQuantity,
            'description' => $this->description,
            'categories' => $this->categoryIds === [] ? null : array_values($this->categoryIds),
            'sale_price' => $this->salePrice === null ? null : (float) SallaMoney::toDecimal($this->salePrice->amountMinor),
            'sku' => $this->sku,
            'require_shipping' => $this->requireShipping,
            'weight' => $this->weight,
            'weight_type' => $this->weightType,
            'status' => $this->status?->value,
        ], static fn ($v): bool => $v !== null);
    }
}
