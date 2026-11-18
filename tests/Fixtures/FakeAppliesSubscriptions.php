<?php

namespace Omarsenusi\MarketplacePlatforms\Tests\Fixtures;

use Omarsenusi\MarketplacePlatforms\Billing\ExternalSubscription;
use Omarsenusi\MarketplacePlatforms\Billing\SyncOutcome;
use Omarsenusi\MarketplacePlatforms\Contracts\AppliesSubscriptions;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Recording test applier: applies nothing host-side, but records every
 * call so tests can assert the package delegated correctly.
 */
final class FakeAppliesSubscriptions implements AppliesSubscriptions
{
    /** @var list<array{kind: string, connection: string, plan: ?string, event: string}> */
    public array $calls = [];

    public function apply(PlatformConnection $connection, ExternalSubscription $subscription, ?string $planId): SyncOutcome
    {
        $this->calls[] = [
            'kind' => 'apply',
            'connection' => $connection->id,
            'plan' => $planId,
            'event' => $subscription->eventName,
        ];

        return new SyncOutcome("Applied: workspace moved to \"{$planId}\" ({$subscription->state}).");
    }

    public function lapse(PlatformConnection $connection, ExternalSubscription $subscription): SyncOutcome
    {
        $this->calls[] = [
            'kind' => 'lapse',
            'connection' => $connection->id,
            'plan' => null,
            'event' => $subscription->eventName,
        ];

        return new SyncOutcome('Applied: subscription ended.');
    }
}
