<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class ShipmentTrackingEvent
{
    public function __construct(
        public ?string $status = null,
        public ?string $note = null,
        public ?string $createdAt = null,
        public array $raw = [],
    ) {}
}
