<?php

namespace Omarsenusi\MarketplacePlatforms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Fired after a live connection's store settings are re-synced from
 * the marketplace profile (e.g. Salla app.updated).
 */
final class StoreProfileRefreshed
{
    use Dispatchable;

    public function __construct(
        public readonly PlatformConnection $connection,
        public readonly StoreProfile $profile,
    ) {}
}
