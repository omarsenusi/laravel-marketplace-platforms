<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * New/updated zone payload: {company, shipping:{...}}.
 *
 * @param  list<int>  $citiesExcluded
 */
final readonly class ZoneInput
{
    public function __construct(
        public ZoneFee $fees,
        public string $duration,
        public ?int $countryId = null,
        public ?int $cityId = null,
        public array $citiesExcluded = [],
        public ?ZoneCashOnDelivery $cashOnDelivery = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(int $companyId): array
    {
        return [
            'company' => $companyId,
            'shipping' => array_filter([
                'country' => $this->countryId,
                'city' => $this->cityId,
                'cities_excluded' => $this->citiesExcluded === [] ? null : array_values($this->citiesExcluded),
                'cash_on_delivery' => $this->cashOnDelivery?->toSallaArray(),
                'fees' => $this->fees->toSallaArray(),
                'duration' => $this->duration,
            ], static fn ($v): bool => $v !== null),
        ];
    }
}
