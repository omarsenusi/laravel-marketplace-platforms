<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * One allowed/excluded area rule inside an `areas` route condition.
 *
 * @param  list<int>  $cityIds
 */
final readonly class AreaRule
{
    public function __construct(
        public string $type,
        public int $countryId,
        public array $cityIds = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return [
            'type' => $this->type,
            'areas' => [[
                'country_id' => $this->countryId,
                'city_ids' => array_values($this->cityIds),
            ]],
        ];
    }
}
