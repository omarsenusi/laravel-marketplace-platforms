<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Branded-route pricing block.
 */
final readonly class RouteBrandingPricing
{
    public function __construct(
        public string $type,
        public int $costMinor,
        public ?int $amountPerUnitMinor = null,
        public ?float $upToWeight = null,
        public ?float $perUnit = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'cost' => (float) SallaMoney::toDecimal($this->costMinor),
            'amount_per_unit' => $this->amountPerUnitMinor === null
                ? null
                : (float) SallaMoney::toDecimal($this->amountPerUnitMinor),
            'up_to_weight' => $this->upToWeight,
            'per_unit' => $this->perUnit,
        ], static fn ($v): bool => $v !== null);
    }
}
