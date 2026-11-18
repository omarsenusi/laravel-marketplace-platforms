<?php

namespace Omarsenusi\MarketplacePlatforms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Fired after a fresh store install is provisioned (new connection row).
 */
final class StoreProvisioned
{
    use Dispatchable;

    public function __construct(
        public readonly PlatformConnection $connection,
        public readonly bool $isNewUser,
        public readonly string $source,
    ) {}
}
