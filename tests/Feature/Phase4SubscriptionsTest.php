<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Billing\SubscriptionSync;
use Omarsenusi\MarketplacePlatforms\Events\SubscriptionChanged;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Jobs\ReapplyPlatformSubscriptions;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\PlatformPlanMapping;
use Omarsenusi\MarketplacePlatforms\Services\WebhookDeliveryService;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;

function subPlanId(): string
{
    return (string) Str::uuid();
}

function phase4ProvisionedSalla(string $storeId = 'merchant-sub-1'): array
{
    Mail::fake();
    Bus::fake();
    phase3FakeSalla($storeId);
    $platform = phase3SallaPlatform();

    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload($storeId));

    $connection = phase3ConnectionFor($platform, $storeId);
    expect($connection)->not->toBeNull();

    return [$platform, $connection->refresh()];
}

function phase4SallaSubPayload(string $storeId, string $event, array $data = []): array
{
    return [
        'event' => $event,
        'merchant' => $storeId,
        'created_at' => '2026-11-01 10:00:00',
        'data' => array_merge([
            'subscription_id' => 'sub-1',
            'item_type' => 'plan',
            'plan_name' => 'Growth',
            'plan_type' => 'Monthly Plan',
            'plan_period' => 'month',
            'start_date' => '2026-11-01 10:00:00',
            'end_date' => '2026-12-01 10:00:00',
            'renew_date' => '2026-12-01 10:00:00',
            'price' => 4900,
            'total' => 4900,
            'store_type' => 'store',
        ], $data),
    ];
}

function phase4RunSub(Platform $platform, PlatformConnection $connection, string $topic, array $payload): void
{
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint, [
        'topic' => $topic,
        'payload' => $payload,
        'connection_id' => $connection->id,
    ]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));
}

test('salla subscription.started with a mapping calls the bound applier and fires the event', function () {
    Event::fake([SubscriptionChanged::class]);
    [$platform, $connection] = phase4ProvisionedSalla();
    $planId = subPlanId();

    $platform->planMappings()->create([
        'external_plan_key' => 'Growth',
        'plan_id' => $planId,
    ]);

    phase4RunSub($platform, $connection, 'app.subscription.started', phase4SallaSubPayload($connection->external_store_id, 'app.subscription.started'));

    $connection = $connection->refresh();

    // Normalized snapshot stored, mapped + applied.
    expect($connection->subscription['mapped_plan_id'])->toBe($planId);
    expect($connection->subscription['applied'])->toBeTrue();
    expect($connection->subscription['state'])->toBe('active');
    expect($connection->subscription['external_plan_key'])->toBe('Growth');
    expect($connection->subscription_event_at)->not->toBeNull();

    // The host applier was called with the mapped plan id.
    $applier = $this->applier();
    expect($applier->calls)->toHaveCount(1);
    expect($applier->calls[0]['kind'])->toBe('apply');
    expect($applier->calls[0]['plan'])->toBe($planId);

    Event::assertDispatched(SubscriptionChanged::class, fn ($event) => $event->connection->id === $connection->id
        && $event->subscription->externalPlanKey === 'Growth'
        && $event->needsAction === false);
});

test('salla subscription.renewed moves the stored period end', function () {
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-2');
    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => 'Growth', 'plan_id' => $planId]);

    phase4RunSub($platform, $connection, 'app.subscription.started', phase4SallaSubPayload($connection->external_store_id, 'app.subscription.started', [
        'renew_date' => '2026-12-01 10:00:00',
    ]));

    $first = $connection->refresh()->subscription['ends_at'];

    phase4RunSub($platform, $connection->refresh(), 'app.subscription.renewed', array_merge(
        phase4SallaSubPayload($connection->external_store_id, 'app.subscription.renewed', ['renew_date' => '2027-01-01 10:00:00']),
        ['created_at' => '2026-12-01 10:00:00'],
    ));

    $second = $connection->refresh()->subscription['ends_at'];
    expect($second)->not->toBe($first);
    expect($second)->toContain('2027-01-01');
});

test('salla addon item_type is ignored without storing a snapshot', function () {
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-3');

    phase4RunSub($platform, $connection, 'app.subscription.started', phase4SallaSubPayload($connection->external_store_id, 'app.subscription.started', [
        'item_type' => 'addon',
    ]));

    $connection = $connection->refresh();
    expect($connection->subscription)->toBeNull();
    expect($this->applier()->calls)->toBeEmpty();
});

test('salla subscription with no mapping stores the snapshot unapplied, and applies after mapping', function () {
    Event::fake([SubscriptionChanged::class]);
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-4');

    phase4RunSub($platform, $connection, 'app.subscription.started', phase4SallaSubPayload($connection->external_store_id, 'app.subscription.started'));

    $connection = $connection->refresh();
    expect($connection->subscription['mapped_plan_id'])->toBeNull();
    expect($connection->subscription['applied'])->toBeFalse();
    expect($this->applier()->calls)->toBeEmpty();

    Event::assertDispatched(SubscriptionChanged::class, fn ($event) => $event->needsAction === true);

    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => 'Growth', 'plan_id' => $planId]);

    app(SubscriptionSync::class)->applyStored($connection->refresh());

    $connection = $connection->refresh();
    expect($connection->subscription['applied'])->toBeTrue();
    expect($connection->subscription['mapped_plan_id'])->toBe($planId);

    $calls = $this->applier()->calls;
    expect($calls)->toHaveCount(1);
    expect($calls[0]['plan'])->toBe($planId);
});

test('an unmapped plan delivery is processed with needs_action, and retrying after mapping applies it', function () {
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-5');

    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint, [
        'topic' => 'app.subscription.started',
        'payload' => phase4SallaSubPayload($connection->external_store_id, 'app.subscription.started'),
        'connection_id' => $connection->id,
    ]);
    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));

    $delivery = $delivery->refresh();
    expect($delivery->status->value)->toBe('processed');
    expect($delivery->needs_action)->toBeTrue();
    expect($delivery->outcome)->toContain('has no plan mapping');

    // Retryable, and the service resets it to pending + re-dispatches.
    expect(WebhookDeliveryService::canRetry($delivery))->toBeTrue();

    Bus::fake();
    expect(app(WebhookDeliveryService::class)->retry($delivery))->toBeTrue();
    Bus::assertDispatched(ProcessPlatformWebhook::class);

    expect($delivery->refresh()->status->value)->toBe('pending');
    expect($delivery->refresh()->outcome)->toBeNull();

    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => 'Growth', 'plan_id' => $planId]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));

    $delivery = $delivery->refresh();
    expect($delivery->outcome)->toStartWith('Applied:');
    expect($delivery->needs_action)->toBeFalse();
    expect($connection->refresh()->subscription['applied'])->toBeTrue();
});

test('salla trial.started falls back to the __trial__ mapping', function () {
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-6');
    $trialId = subPlanId();

    $platform->planMappings()->create(['external_plan_key' => PlatformPlanMapping::TRIAL_KEY, 'plan_id' => $trialId]);

    phase4RunSub($platform, $connection, 'app.trial.started', phase4SallaSubPayload($connection->external_store_id, 'app.trial.started', [
        'plan_name' => 'Free Trial Plan',
        'renew_date' => null,
        'end_date' => '2026-11-15 10:00:00',
    ]));

    $connection = $connection->refresh();
    expect($connection->subscription['state'])->toBe('trialing');
    expect($connection->subscription['applied'])->toBeTrue();

    $calls = $this->applier()->calls;
    expect($calls)->toHaveCount(1);
    expect($calls[0]['plan'])->toBe($trialId);
});

test('salla subscription.expired calls lapse on the bound applier', function () {
    Event::fake([SubscriptionChanged::class]);
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-7');
    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => 'Growth', 'plan_id' => $planId]);

    phase4RunSub($platform, $connection, 'app.subscription.started', phase4SallaSubPayload($connection->external_store_id, 'app.subscription.started'));
    expect($connection->refresh()->subscription['applied'])->toBeTrue();

    phase4RunSub($platform, $connection->refresh(), 'app.subscription.expired', array_merge(
        phase4SallaSubPayload($connection->external_store_id, 'app.subscription.expired'),
        ['created_at' => '2026-12-02 10:00:00'],
    ));

    $connection = $connection->refresh();
    expect($connection->subscription['state'])->toBe('lapsed');

    $calls = $this->applier()->calls;
    expect($calls)->toHaveCount(2);
    expect($calls[1]['kind'])->toBe('lapse');

    Event::assertDispatched(SubscriptionChanged::class, fn ($event) => $event->subscription->state === 'lapsed');
});

test('lapse for a store without a workspace is only recorded', function () {
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-8');
    $connection->forceFill(['workspace_id' => null])->save();

    phase4RunSub($platform, $connection->refresh(), 'app.subscription.expired', array_merge(
        phase4SallaSubPayload($connection->external_store_id, 'app.subscription.expired'),
        ['created_at' => '2026-12-02 10:00:00'],
    ));

    $connection = $connection->refresh();
    expect($connection->subscription['state'])->toBe('lapsed');
    expect($this->applier()->calls)->toBeEmpty();
});

test('older events never overwrite newer subscription state', function () {
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-9');
    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => 'Growth', 'plan_id' => $planId]);

    $newer = phase4SallaSubPayload($connection->external_store_id, 'app.subscription.renewed', ['renew_date' => '2027-01-01 10:00:00']);
    $newer['created_at'] = '2026-12-01 10:00:00';
    phase4RunSub($platform, $connection, 'app.subscription.renewed', $newer);

    $storedAt = $connection->refresh()->subscription_event_at->toIso8601String();

    $older = phase4SallaSubPayload($connection->external_store_id, 'app.subscription.started', ['renew_date' => '2026-12-01 10:00:00']);
    $older['created_at'] = '2026-11-01 10:00:00';
    phase4RunSub($platform, $connection->refresh(), 'app.subscription.started', $older);

    $connection = $connection->refresh();
    expect($connection->subscription_event_at->toIso8601String())->toBe($storedAt);
    expect($connection->subscription['event_name'])->toBe('app.subscription.renewed');
});

test('redelivery of the same event re-applies idempotently', function () {
    [$platform, $connection] = phase4ProvisionedSalla('merchant-sub-10');
    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => 'Growth', 'plan_id' => $planId]);

    $payload = phase4SallaSubPayload($connection->external_store_id, 'app.subscription.started');
    phase4RunSub($platform, $connection, 'app.subscription.started', $payload);
    phase4RunSub($platform, $connection->refresh(), 'app.subscription.started', $payload);

    expect($connection->refresh()->subscription['applied'])->toBeTrue();
    expect($this->applier()->calls)->toHaveCount(2);
});

function phase4ZidSubPayload(string $storeId, string $event, array $over = []): array
{
    return array_merge([
        'event' => $event,
        'event_name' => $event,
        'app_id' => 'app_1',
        'store_id' => $storeId,
        'store_url' => 'https://zaid.myzid.shop',
        'plan_name' => 'Growth Annual',
        'plan_id' => 77,
        'plan_type' => 'Annual',
        'status' => 'active',
        'start_date' => '2026-11-01T10:00:00+03:00',
        'end_date' => '2027-11-01T10:00:00+03:00',
        'amount_paid' => 490.0,
        'payment_date' => '2026-11-01T10:00:00+03:00',
        'merchant_email' => 'zaid@zaid-store.sa',
    ], $over);
}

test('zid subscription.active before install stores pending, oauth provisioning applies it', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    $connection = phase3ConnectionFor($platform, '424242');
    expect($connection)->toBeNull();

    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => '77', 'plan_id' => $planId]);

    // Subscription lands before the install: pending_oauth, unapplied.
    phase3RunDelivery($platform, 'app.market.subscription.active', phase4ZidSubPayload('424242', 'app.market.subscription.active'));

    $connection = phase3ConnectionFor($platform, '424242');
    expect($connection)->not->toBeNull();
    expect($connection->workspace_id)->toBeNull();
    expect($connection->subscription['applied'])->toBeFalse();
    expect($connection->subscription['external_plan_key'])->toBe('77');

    // OAuth callback attaches the workspace → snapshot applied.
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-999")->assertRedirect();

    $connection = phase3ActiveConnection($platform, '424242');
    expect($connection->subscription['applied'])->toBeTrue();

    $calls = $this->applier()->calls;
    expect($calls)->not->toBeEmpty();
    expect($calls[0]['plan'])->toBe($planId);
});

test('zid free trial maps to trialing', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-1',
    ]);
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-111")->assertRedirect();

    $connection = phase3ActiveConnection($platform, '424242');
    $trialId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => PlatformPlanMapping::TRIAL_KEY, 'plan_id' => $trialId]);

    phase4RunSub($platform, $connection, 'app.market.subscription.active', phase4ZidSubPayload('424242', 'app.market.subscription.active', [
        'plan_id' => 999,
        'plan_type' => 'Free Trial',
    ]));

    expect($connection->refresh()->subscription['state'])->toBe('trialing');

    $calls = $this->applier()->calls;
    expect($calls)->toHaveCount(1);
    expect($calls[0]['plan'])->toBe($trialId);
});

test('zid refunded lapses the subscription', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => '77', 'plan_id' => $planId]);

    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-1',
    ]);
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-222")->assertRedirect();
    $connection = phase3ActiveConnection($platform, '424242');

    phase4RunSub($platform, $connection, 'app.market.subscription.active', phase4ZidSubPayload('424242', 'app.market.subscription.active'));
    expect($connection->refresh()->subscription['state'])->toBe('active');

    $refunded = phase4ZidSubPayload('424242', 'app.market.subscription.refunded');
    $refunded['payment_date'] = '2026-12-02T10:00:00+03:00';
    phase4RunSub($platform, $connection->refresh(), 'app.market.subscription.refunded', $refunded);

    expect($connection->refresh()->subscription['state'])->toBe('lapsed');

    $calls = $this->applier()->calls;
    expect($calls[1]['kind'] ?? null)->toBe('lapse');
});

test('zid warning stores the snapshot without touching the plan', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-1',
    ]);
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-333")->assertRedirect();
    $connection = phase3ActiveConnection($platform, '424242');

    phase4RunSub($platform, $connection, 'app.market.subscription.warning', phase4ZidSubPayload('424242', 'app.market.subscription.warning'));

    $connection = $connection->refresh();
    expect($connection->subscription['state'])->toBe('ignored');
    expect($this->applier()->calls)->toBeEmpty();
});

test('bulk re-apply processes every pending snapshot', function () {
    Mail::fake();
    phase3FakeSalla('merchant-sub-13');
    $platform = phase3SallaPlatform();
    $planId = subPlanId();
    $platform->planMappings()->create(['external_plan_key' => 'Growth', 'plan_id' => $planId]);

    for ($i = 0; $i < 3; $i++) {
        $storeId = "bulk-{$i}";
        phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload($storeId));
        $connection = phase3ConnectionFor($platform, $storeId);
        // Simulate an unapplied snapshot without re-running webhooks.
        $connection->forceFill([
            'subscription' => [
                'driver' => 'salla',
                'external_plan_key' => 'Growth',
                'plan_name' => 'Growth',
                'plan_type' => null,
                'state' => 'active',
                'starts_at' => null,
                'ends_at' => null,
                'external_subscription_id' => null,
                'amount' => null,
                'event_name' => 'app.subscription.started',
                'occurred_at' => now()->toIso8601String(),
                'mapped_plan_id' => null,
                'applied' => false,
            ],
            'subscription_event_at' => now(),
        ])->save();
    }

    (new ReapplyPlatformSubscriptions($platform->id))->handle(app(SubscriptionSync::class));

    expect($this->applier()->calls)->toHaveCount(3);
});
