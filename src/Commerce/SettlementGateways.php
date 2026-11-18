<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Resolves the SettlementGateway for a connection's driver key.
 */
final class SettlementGateways
{
    public static function for(PlatformConnection $connection): SettlementGateway
    {
        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        return match ($driverKey) {
            'salla' => app(SallaSettlementGateway::class),
            default => throw UnsupportedOperation::for($driverKey, 'SettlementGateway'),
        };
    }
}
