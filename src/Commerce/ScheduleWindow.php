<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Days window for a `schedule` route condition (`within_range`).
 *
 * The schedule value shape beyond day names was not fully verifiable in
 * the docs (https://docs.salla.dev/shipping-routes/create.md), so only
 * day names plus optional from/to bounds are mapped.
 *
 * @param  list<string>  $days
 */
final readonly class ScheduleWindow
{
    public function __construct(
        public array $days,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'days' => array_values($this->days),
            'from' => $this->from,
            'to' => $this->to,
        ], static fn ($v): bool => $v !== null);
    }
}
