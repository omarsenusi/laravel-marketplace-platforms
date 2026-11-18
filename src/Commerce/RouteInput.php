<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * New-route payload for POST /shipping/routes.
 *
 * @param  list<RouteCompany>  $companies
 * @param  list<RouteCondition>  $conditions
 */
final readonly class RouteInput
{
    public function __construct(
        public string $name,
        public RouteType $type,
        public int $priority,
        public array $companies,
        public ConditionMatch $conditionMatch,
        public array $conditions,
        public bool|int|null $status = null,
        public ?RouteBranding $branded = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type->value,
            'priority' => $this->priority,
            'status' => $this->status,
            'companies' => array_map(
                static fn (RouteCompany $c): array => $c->toSallaArray(),
                $this->companies,
            ),
            'condition_match' => $this->conditionMatch->value,
            'conditions' => array_map(
                static fn (RouteCondition $c): array => $c->toSallaArray(),
                $this->conditions,
            ),
            'branded' => $this->branded?->toSallaArray(),
        ], static fn ($v): bool => $v !== null);
    }
}
