<?php

namespace Omarsenusi\MarketplacePlatforms\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Contracts\AppliesSubscriptions;
use Omarsenusi\MarketplacePlatforms\Events\SubscriptionChanged;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\PlatformPlanMapping;

/**
 * Applies a normalized marketplace subscription event to a
 * connection: persists the last-known subscription snapshot,
 * resolves the `platform_plan_mappings` row, and delegates the
 * host-side plan change to the bound AppliesSubscriptions
 * implementation. Fires SubscriptionChanged for every outcome.
 *
 * Idempotent: equal occurredAt re-applies, older events are
 * dropped (out-of-order guard). Never logs tokens.
 */
final class SubscriptionSync
{
    /**
     * Persist the normalized subscription snapshot and, when the
     * connection already has a workspace, apply the plan change via
     * the host applier. Returns a short human-readable outcome that
     * the webhook handler records on the delivery, so "processed"
     * never hides a no-op (e.g. an unmapped plan).
     */
    public function apply(PlatformConnection $connection, ExternalSubscription $subscription): SyncOutcome
    {
        $lock = Cache::lock("platforms:subscription:{$connection->id}", 30);

        try {
            $lock->block(10);
        } catch (\Throwable $e) {
            Log::warning('platforms.subscription.lock_timeout', [
                'connection_id' => $connection->id,
                'event' => $subscription->eventName,
            ]);

            // Retry with backoff instead of reporting a silent success.
            throw new \RuntimeException('Subscription sync is busy for this store; will retry.');
        }

        try {
            $connection->refresh();

            // Normalize to UTC: the datetime columns store naive wall
            // time (sqlite), so a +03:00 instant must be converted
            // before persisting/comparing or redeliveries read back as
            // a different instant and trip the out-of-order guard.
            $subscription = new ExternalSubscription(
                driver: $subscription->driver,
                externalPlanKey: $subscription->externalPlanKey,
                planName: $subscription->planName,
                planType: $subscription->planType,
                state: $subscription->state,
                startsAt: $subscription->startsAt?->utc(),
                endsAt: $subscription->endsAt?->utc(),
                externalSubscriptionId: $subscription->externalSubscriptionId,
                amount: $subscription->amount,
                eventName: $subscription->eventName,
                occurredAt: $subscription->occurredAt->utc(),
                raw: $subscription->raw,
            );

            $storedAt = $connection->subscription_event_at;

            if ($storedAt !== null && $subscription->occurredAt->lt($storedAt)) {
                Log::info('platforms.subscription.out_of_order', [
                    'connection_id' => $connection->id,
                    'event' => $subscription->eventName,
                    'occurred_at' => $subscription->occurredAt->toIso8601String(),
                    'stored_at' => $storedAt->toIso8601String(),
                ]);

                return $this->finish($connection, $subscription, new SyncOutcome('Skipped: a newer subscription event was already applied.'));
            }

            $mapping = null;
            $applied = false;
            $outcome = '';
            $needsAction = false;

            if (in_array($subscription->state, ['active', 'trialing'], true)) {
                $mapping = $this->resolveMapping(
                    $connection->platform_id,
                    $subscription->externalPlanKey,
                    $subscription->state,
                );

                if ($mapping === null) {
                    Log::warning('platforms.subscription.unmapped', [
                        'platform_id' => $connection->platform_id,
                        'connection_id' => $connection->id,
                        'external_plan_key' => $subscription->externalPlanKey,
                        'event' => $subscription->eventName,
                    ]);

                    $outcome = "Not applied: plan \"{$subscription->externalPlanKey}\" has no plan mapping. Add a mapping, then retry.";
                    $needsAction = true;
                } elseif ($connection->workspace_id !== null) {
                    $applierOutcome = $this->applier()->apply($connection->refresh(), $subscription, $mapping->plan_id);
                    $applied = ! $applierOutcome->needsAction;
                    $outcome = $applierOutcome->message;
                    $needsAction = $applierOutcome->needsAction;
                } else {
                    $outcome = 'Stored: the store has no workspace yet; it applies after the account is created.';
                }
            } elseif ($subscription->state === 'lapsed') {
                // Lapses carry no pending work once recorded: applyStored
                // only retries active/trialing snapshots.
                if ($connection->workspace_id !== null) {
                    $applierOutcome = $this->applier()->lapse($connection->refresh(), $subscription);
                    $applied = ! $applierOutcome->needsAction;
                    $outcome = $applierOutcome->message;
                    $needsAction = $applierOutcome->needsAction;
                } else {
                    $applied = true;
                    $outcome = 'Recorded: lapse for a store without a workspace.';
                    Log::info('platforms.subscription.lapsed_without_workspace', [
                        'connection_id' => $connection->id,
                        'event' => $subscription->eventName,
                    ]);
                }
            } else {
                // `ignored` (e.g. Zid warning): stored for
                // visibility, nothing to apply.
                $applied = true;
                $outcome = "Recorded only: {$subscription->eventName} does not change the plan.";
            }

            $stored = $subscription->toArray();
            $stored['mapped_plan_id'] = $mapping?->plan_id;
            $stored['applied'] = $applied;

            $connection->forceFill([
                'subscription' => $stored,
                'subscription_event_at' => $subscription->occurredAt,
            ])->save();

            return $this->finish($connection, $subscription, new SyncOutcome($outcome, $needsAction));
        } finally {
            $lock->release();
        }
    }

    /**
     * Re-apply the stored snapshot after the workspace was attached
     * (pending Zid installs) or after the host added a mapping.
     * No-op unless the stored state is active/trialing with pending
     * work (unmapped or unapplied).
     */
    public function applyStored(PlatformConnection $connection): void
    {
        $connection->refresh();

        $stored = is_array($connection->subscription) ? $connection->subscription : null;

        if ($stored === null) {
            return;
        }

        $state = $stored['state'] ?? null;

        if (! in_array($state, ['active', 'trialing'], true)) {
            return;
        }

        if (($stored['applied'] ?? false) === true && ($stored['mapped_plan_id'] ?? null) !== null) {
            return;
        }

        $this->apply($connection, $this->rebuild($connection, $stored));
    }

    private function finish(PlatformConnection $connection, ExternalSubscription $subscription, SyncOutcome $outcome): SyncOutcome
    {
        SubscriptionChanged::dispatch($connection->refresh(), $subscription, $outcome->message, $outcome->needsAction);

        return $outcome;
    }

    private function resolveMapping(string $platformId, string $key, string $state): ?PlatformPlanMapping
    {
        $mapping = PlatformPlanMapping::query()
            ->where('platform_id', $platformId)
            ->where('external_plan_key', $key)
            ->first();

        if ($mapping === null && $state === 'trialing') {
            $mapping = PlatformPlanMapping::query()
                ->where('platform_id', $platformId)
                ->where('external_plan_key', PlatformPlanMapping::TRIAL_KEY)
                ->first();
        }

        return $mapping;
    }

    private function applier(): AppliesSubscriptions
    {
        return app(AppliesSubscriptions::class);
    }

    /**
     * Rebuild a DTO from the stored snapshot (for re-apply).
     *
     * @param  array<string, mixed>  $stored
     */
    private function rebuild(PlatformConnection $connection, array $stored): ExternalSubscription
    {
        $parse = static function (?string $value): ?CarbonImmutable {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            try {
                return CarbonImmutable::parse($value);
            } catch (\Throwable) {
                return null;
            }
        };

        $occurredAt = $parse(is_string($stored['occurred_at'] ?? null) ? $stored['occurred_at'] : null)
            ?? CarbonImmutable::now();

        return new ExternalSubscription(
            driver: (string) ($stored['driver'] ?? $connection->platform->driver()->key()),
            externalPlanKey: (string) ($stored['external_plan_key'] ?? ''),
            planName: is_string($stored['plan_name'] ?? null) ? $stored['plan_name'] : null,
            planType: is_string($stored['plan_type'] ?? null) ? $stored['plan_type'] : null,
            state: (string) ($stored['state'] ?? 'active'),
            startsAt: $parse(is_string($stored['starts_at'] ?? null) ? $stored['starts_at'] : null),
            endsAt: $parse(is_string($stored['ends_at'] ?? null) ? $stored['ends_at'] : null),
            externalSubscriptionId: is_string($stored['external_subscription_id'] ?? null) ? $stored['external_subscription_id'] : null,
            amount: is_numeric($stored['amount'] ?? null) ? (float) $stored['amount'] : null,
            eventName: (string) ($stored['event_name'] ?? ''),
            occurredAt: $occurredAt,
            raw: [],
        );
    }
}
