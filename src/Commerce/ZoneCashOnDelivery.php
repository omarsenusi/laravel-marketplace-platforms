<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Cash-on-delivery surcharge for a shipping zone.
 */
final readonly class ZoneCashOnDelivery
{
    public function __construct(
        public bool $status,
        public int $feesMinor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return [
            'status' => $this->status,
            'fees' => (float) SallaMoney::toDecimal($this->feesMinor),
        ];
    }
}
