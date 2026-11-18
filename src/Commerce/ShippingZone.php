<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class ShippingZone
{
    public function __construct(
        public string $id,
        public ?string $zoneCode = null,
        public ?string $companyId = null,
        public ?int $countryId = null,
        public ?int $cityId = null,
        public ?ZoneFee $fees = null,
        public ?string $feesCurrency = null,
        public ?ZoneCashOnDelivery $cashOnDelivery = null,
        public ?string $duration = null,
        public array $raw = [],
    ) {}
}
