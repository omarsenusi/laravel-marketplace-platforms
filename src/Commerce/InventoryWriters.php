<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the InventoryWriter for a connection's driver key.
 */
final class InventoryWriters
{
    public static function for(PlatformConnection $connection): InventoryWriter
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaInventoryWriter::class),
            default => throw UnsupportedOperation::for($driverKey, 'InventoryWriter'),
        };
    }
}
