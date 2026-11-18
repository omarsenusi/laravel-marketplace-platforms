<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Zone delivery fee for POST /shipping/zones {shipping:{fees:{...}}}.
 */
final readonly class ZoneFee
{
    public function __construct(
        public ZoneFeeType $type,
        public int $amountMinor,
        public ?float $upToWeight = null,
        public ?int $amountPerUnitMinor = null,
        public ?float $perUnit = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'amount' => (float) SallaMoney::toDecimal($this->amountMinor),
            'up_to_weight' => $this->upToWeight,
            'amount_per_unit' => $this->amountPerUnitMinor === null
                ? null
                : (float) SallaMoney::toDecimal($this->amountPerUnitMinor),
            'per_unit' => $this->perUnit,
        ], static fn ($v): bool => $v !== null);
    }
}
