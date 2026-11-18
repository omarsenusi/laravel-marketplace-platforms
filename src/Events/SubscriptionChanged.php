<?php

namespace Omarsenusi\MarketplacePlatforms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Omarsenusi\MarketplacePlatforms\Billing\ExternalSubscription;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Fired after a marketplace subscription event is applied (or
 * recorded) for a connection.
 */
final class SubscriptionChanged
{
    use Dispatchable;

    public function __construct(
        public readonly PlatformConnection $connection,
        public readonly ExternalSubscription $subscription,
        public readonly string $outcome,
        public readonly bool $needsAction = false,
    ) {}
}
