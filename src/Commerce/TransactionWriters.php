<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the TransactionWriter for a connection's driver key.
 */
final class TransactionWriters
{
    public static function for(PlatformConnection $connection): TransactionWriter
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaTransactionWriter::class),
            default => throw UnsupportedOperation::for($driverKey, 'TransactionWriter'),
        };
    }
}
