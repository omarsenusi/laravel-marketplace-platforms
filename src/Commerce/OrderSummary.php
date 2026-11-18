<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class OrderSummary
{
    public function __construct(
        public string $id,
        public ?int $referenceId = null,
        public ?string $statusName = null,
        public ?string $statusSlug = null,
        public ?Money $total = null,
        public array $raw = [],
    ) {}
}
