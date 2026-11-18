<?php

namespace Omarsenusi\MarketplacePlatforms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Fired after a connection is uninstalled (tokens cleared, claims expired).
 */
final class StoreUninstalled
{
    use Dispatchable;

    public function __construct(
        public readonly PlatformConnection $connection,
        public readonly string $source,
    ) {}
}
