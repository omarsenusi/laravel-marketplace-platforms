<?php

namespace Omarsenusi\MarketplacePlatforms\Catalog;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use RuntimeException;

/**
 * Resolves the CatalogReader for a connection's driver key.
 */
final class CatalogReaders
{
    public static function for(PlatformConnection $connection): CatalogReader
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaCatalogReader::class),
            'zid' => app(ZidCatalogReader::class),
            default => throw new RuntimeException("No catalog reader for driver [{$driverKey}]."),
        };
    }
}
