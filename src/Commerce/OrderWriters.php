<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the OrderWriter for a connection's driver key.
 */
final class OrderWriters
{
    public static function for(PlatformConnection $connection): OrderWriter
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaOrderWriter::class),
            default => throw UnsupportedOperation::for($driverKey, 'OrderWriter'),
        };
    }
}
