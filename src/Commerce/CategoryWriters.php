<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the CategoryWriter for a connection's driver key.
 */
final class CategoryWriters
{
    public static function for(PlatformConnection $connection): CategoryWriter
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaCategoryWriter::class),
            default => throw UnsupportedOperation::for($driverKey, 'CategoryWriter'),
        };
    }
}
