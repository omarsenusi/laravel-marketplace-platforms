<?php

namespace Omarsenusi\MarketplacePlatforms\Contracts;

use Omarsenusi\MarketplacePlatforms\Billing\ExternalSubscription;
use Omarsenusi\MarketplacePlatforms\Billing\NullSubscriptionApplier;
use Omarsenusi\MarketplacePlatforms\Billing\SyncOutcome;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Host-side subscription application.
 *
 * The package parses the marketplace event, persists the normalized
 * snapshot on the connection, and resolves the
 * `platform_plan_mappings` row. The host decides what that means for
 * its own plans/subscriptions. Bind your own implementation in a
 * service provider.
 *
 * Without a binding, {@see NullSubscriptionApplier}
 * records the snapshot and reports the outcome as needing action.
 */
interface AppliesSubscriptions
{
    /**
     * Apply an active/trialing subscription. `$planId` is the host-side
     * plan id from the mapping (usually a uuid string) or null when
     * the event state needs no plan (ignored/lapsed snapshots never
     * reach this method).
     */
    public function apply(PlatformConnection $connection, ExternalSubscription $subscription, ?string $planId): SyncOutcome;

    /**
     * Apply a lapsed subscription (expired/canceled/refunded).
     */
    public function lapse(PlatformConnection $connection, ExternalSubscription $subscription): SyncOutcome;
}
