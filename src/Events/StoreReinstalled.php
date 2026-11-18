<?php

namespace Omarsenusi\MarketplacePlatforms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Fired after an existing connection is reactivated by a reinstall.
 */
final class StoreReinstalled
{
    use Dispatchable;

    public function __construct(
        public readonly PlatformConnection $connection,
        public readonly string $source,
    ) {}
}
