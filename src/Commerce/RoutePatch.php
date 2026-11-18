<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Sparse route patch for PATCH /shipping/routes/{id}.
 *
 * @param  list<RouteCompany>|null  $companies
 * @param  list<RouteCondition>|null  $conditions
 */
final readonly class RoutePatch
{
    public function __construct(
        public ?string $name = null,
        public ?bool $status = null,
        public ?int $priority = null,
        public ?RouteType $type = null,
        public ?array $companies = null,
        public ?ConditionMatch $conditionMatch = null,
        public ?array $conditions = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'status' => $this->status,
            'priority' => $this->priority,
            'type' => $this->type?->value,
            'companies' => $this->companies === null
                ? null
                : array_map(static fn (RouteCompany $c): array => $c->toSallaArray(), $this->companies),
            'condition_match' => $this->conditionMatch?->value,
            'conditions' => $this->conditions === null
                ? null
                : array_map(static fn (RouteCondition $c): array => $c->toSallaArray(), $this->conditions),
        ], static fn ($v): bool => $v !== null);
    }
}
