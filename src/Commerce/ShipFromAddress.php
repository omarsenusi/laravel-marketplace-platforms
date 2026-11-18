<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Address origin for ship_from ({type: address, ...national address}).
 */
final readonly class ShipFromAddress
{
    public function __construct(public NationalAddress $address) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_merge(['type' => 'address'], $this->address->toSallaArray());
    }
}
