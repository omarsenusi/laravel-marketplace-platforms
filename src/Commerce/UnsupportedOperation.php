<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use RuntimeException;

/**
 * Thrown when a Commerce capability is resolved for a driver that has no
 * implementation (e.g. any write capability on Zid). Resolution-time
 * only — capability methods themselves never throw for HTTP failures.
 */
final class UnsupportedOperation extends RuntimeException
{
    public static function for(string $driverKey, string $capability): self
    {
        return new self("No {$capability} implementation for driver [{$driverKey}].");
    }
}
