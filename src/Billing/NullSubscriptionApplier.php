<?php

namespace Omarsenusi\MarketplacePlatforms\Billing;

use Omarsenusi\MarketplacePlatforms\Contracts\AppliesSubscriptions;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Default AppliesSubscriptions binding: the package records the
 * normalized snapshot on the connection, and the host observes the
 * SubscriptionChanged event to apply its own plan changes. Reports
 * every active/trialing event as needing action until the host binds
 * a real applier.
 */
final class NullSubscriptionApplier implements AppliesSubscriptions
{
    public function apply(PlatformConnection $connection, ExternalSubscription $subscription, ?string $planId): SyncOutcome
    {
        return new SyncOutcome(
            'Recorded: no subscription applier is bound (bind '.AppliesSubscriptions::class.' to apply host plans).',
            true,
        );
    }

    public function lapse(PlatformConnection $connection, ExternalSubscription $subscription): SyncOutcome
    {
        return new SyncOutcome('Recorded: lapse for a store (no subscription applier is bound).');
    }
}
