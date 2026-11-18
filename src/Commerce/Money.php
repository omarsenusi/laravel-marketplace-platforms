<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Money in integer minor units (halalas for SAR) with an ISO currency code.
 *
 * All Commerce DTOs carry money this way; conversion to Salla's decimal
 * amounts (2 dp) happens only at the HTTP edge via SallaMoney.
 */
final readonly class Money
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
    ) {}
}
