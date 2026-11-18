<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Bounds for a `between` route condition.
 */
final readonly class BetweenRange
{
    public function __construct(
        public string|int|float $min,
        public string|int|float $max,
    ) {}

    /**
     * @return array<string, string|int|float>
     */
    public function toSallaArray(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }
}
