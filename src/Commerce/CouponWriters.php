<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the CouponWriter for a connection's driver key.
 */
final class CouponWriters
{
    public static function for(PlatformConnection $connection): CouponWriter
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaCouponWriter::class),
            default => throw UnsupportedOperation::for($driverKey, 'CouponWriter'),
        };
    }
}
