<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * One package line for POST /shipments {packages:[]}.
 */
final readonly class ShipmentPackage
{
    public function __construct(
        public string $name,
        public int $quantity,
        public ?int $itemId = null,
        public ?string $sku = null,
        public ?Money $price = null,
        public ?float $weightValue = null,
        public ?string $weightUnit = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        $price = $this->price === null
            ? null
            : ['amount' => (float) SallaMoney::toDecimal($this->price->amountMinor), 'currency' => $this->price->currency];

        $weight = $this->weightValue === null
            ? null
            : array_filter(
                ['value' => $this->weightValue, 'units' => $this->weightUnit],
                static fn ($v): bool => $v !== null,
            );

        return array_filter([
            'item_id' => $this->itemId,
            'name' => $this->name,
            'sku' => $this->sku,
            'price' => $price,
            'quantity' => $this->quantity,
            'weight' => $weight,
        ], static fn ($v): bool => $v !== null);
    }
}
