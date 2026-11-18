<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the ProductWriter for a connection's driver key.
 */
final class ProductWriters
{
    public static function for(PlatformConnection $connection): ProductWriter
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaProductWriter::class),
            default => throw UnsupportedOperation::for($driverKey, 'ProductWriter'),
        };
    }
}
