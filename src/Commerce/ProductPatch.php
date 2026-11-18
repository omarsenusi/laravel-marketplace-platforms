<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Sparse product patch for PUT /products/{product}. Only non-null fields
 * are sent. Verified against https://docs.salla.dev/product/update-product.md.
 */
final readonly class ProductPatch
{
    /**
     * @param  list<int>|null  $categoryIds
     */
    public function __construct(
        public ?string $name = null,
        public ?Money $price = null,
        public ?int $quantity = null,
        public ?bool $unlimitedQuantity = null,
        public ?string $description = null,
        public ?array $categoryIds = null,
        public ?Money $salePrice = null,
        public ?string $sku = null,
        public ?bool $requireShipping = null,
        public ?float $weight = null,
        public ?string $weightType = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'price' => $this->price === null ? null : (float) SallaMoney::toDecimal($this->price->amountMinor),
            'quantity' => $this->quantity,
            'unlimited_quantity' => $this->unlimitedQuantity,
            'description' => $this->description,
            'categories' => $this->categoryIds === null ? null : array_values($this->categoryIds),
            'sale_price' => $this->salePrice === null ? null : (float) SallaMoney::toDecimal($this->salePrice->amountMinor),
            'sku' => $this->sku,
            'require_shipping' => $this->requireShipping,
            'weight' => $this->weight,
            'weight_type' => $this->weightType,
        ], static fn ($v): bool => $v !== null);
    }
}
