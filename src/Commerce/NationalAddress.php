<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * National-address shape for ship_to (and address-type ship_from).
 *
 * Field names follow https://docs.salla.dev/shipments/create.md; since
 * 2025-01-05 the national-address fields are mandatory for ship_to.
 */
final readonly class NationalAddress
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $country,
        public string $city,
        public string $addressLine,
        public string $streetNumber,
        public string $block,
        public string $shortAddress,
        public string $buildingNumber,
        public string $additionalNumber,
        public string $postalCode,
        public ?string $latitude = null,
        public ?string $longitude = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'city' => $this->city,
            'address_line' => $this->addressLine,
            'street_number' => $this->streetNumber,
            'block' => $this->block,
            'short_address' => $this->shortAddress,
            'building_number' => $this->buildingNumber,
            'additional_number' => $this->additionalNumber,
            'postal_code' => $this->postalCode,
            'geo_coordinates' => $this->latitude === null || $this->longitude === null
                ? null
                : ['lat' => $this->latitude, 'lng' => $this->longitude],
        ], static fn ($v): bool => $v !== null);
    }
}
