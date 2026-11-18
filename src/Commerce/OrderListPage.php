<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  list<OrderSummary>  $orders
 */
final readonly class OrderListPage
{
    public function __construct(
        public array $orders,
        public ?int $nextPage = null,
        public ?int $total = null,
    ) {}
}
