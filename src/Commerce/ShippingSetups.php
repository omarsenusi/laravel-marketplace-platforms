<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the ShippingSetup for a connection's driver key.
 */
final class ShippingSetups
{
    public static function for(PlatformConnection $connection): ShippingSetup
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaShippingSetup::class),
            default => throw UnsupportedOperation::for($driverKey, 'ShippingSetup'),
        };
    }
}
