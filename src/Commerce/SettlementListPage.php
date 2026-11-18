<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  list<Settlement>  $settlements
 */
final readonly class SettlementListPage
{
    public function __construct(
        public array $settlements,
        public ?int $nextPage = null,
    ) {}
}
