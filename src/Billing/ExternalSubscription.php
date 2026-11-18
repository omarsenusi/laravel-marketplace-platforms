<?php

namespace Omarsenusi\MarketplacePlatforms\Billing;

use Carbon\CarbonImmutable;

/**
 * Normalized marketplace subscription event. Drivers parse their
 * webhook payloads into this shape; SubscriptionSync applies it.
 *
 * `raw` carries only a small, safe subset for display — never
 * tokens or full payloads.
 */
final class ExternalSubscription
{
    /**
     * @param  'active'|'trialing'|'lapsed'|'ignored'  $state
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $externalPlanKey,
        public readonly ?string $planName,
        public readonly ?string $planType,
        public readonly string $state,
        public readonly ?CarbonImmutable $startsAt,
        public readonly ?CarbonImmutable $endsAt,
        public readonly ?string $externalSubscriptionId,
        public readonly ?float $amount,
        public readonly string $eventName,
        public readonly CarbonImmutable $occurredAt,
        public readonly array $raw,
    ) {}

    /**
     * Normalized array persisted on
     * `raw` carries only a small, safe subset for host display.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'external_plan_key' => $this->externalPlanKey,
            'plan_name' => $this->planName,
            'plan_type' => $this->planType,
            'state' => $this->state,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'ends_at' => $this->endsAt?->toIso8601String(),
            'external_subscription_id' => $this->externalSubscriptionId,
            'amount' => $this->amount,
            'event_name' => $this->eventName,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'mapped_plan_id' => null,
            'applied' => false,
        ];
    }
}
