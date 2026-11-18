<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class TransactionUpdate
{
    public function __construct(
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
