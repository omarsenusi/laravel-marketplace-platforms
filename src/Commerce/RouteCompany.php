<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * A company assignment inside a shipping route.
 */
final readonly class RouteCompany
{
    public function __construct(
        public int $id,
        public ?int $priority = null,
        public ?int $capacity = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'priority' => $this->priority,
            'capacity' => $this->capacity,
        ], static fn ($v): bool => $v !== null);
    }
}
