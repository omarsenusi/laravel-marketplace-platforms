<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the ShipmentWriter for a connection's driver key.
 */
final class ShipmentWriters
{
    public static function for(PlatformConnection $connection): ShipmentWriter
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaShipmentWriter::class),
            default => throw UnsupportedOperation::for($driverKey, 'ShipmentWriter'),
        };
    }
}
