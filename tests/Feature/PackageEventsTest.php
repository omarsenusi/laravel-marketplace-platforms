<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Events\PlatformWebhookReceived;
use Omarsenusi\MarketplacePlatforms\Events\StoreProvisioned;
use Omarsenusi\MarketplacePlatforms\Events\SubscriptionChanged;
use Omarsenusi\MarketplacePlatforms\Events\TokensRefreshed;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;

test('an unhandled topic still fires PlatformWebhookReceived', function () {
    Event::fake([PlatformWebhookReceived::class]);

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint, [
        'topic' => 'something.unknown',
        'payload' => ['event' => 'something.unknown', 'merchant' => 'store-x'],
    ]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));

    expect($delivery->refresh()->status->value)->toBe('processed');

    Event::assertDispatched(PlatformWebhookReceived::class, fn ($event) => $event->platformSlug === $platform->slug
        && $event->driverKey === 'salla'
        && $event->topic === 'something.unknown'
        && $event->storeId === 'store-x');
});

test('handled topics fire PlatformWebhookReceived with the resolved connection', function () {
    Event::fake([PlatformWebhookReceived::class, StoreProvisioned::class, TokensRefreshed::class, SubscriptionChanged::class]);
    Mail::fake();
    Bus::fake();
    phase3FakeSalla('merchant-evt-1');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-evt-1'));

    $connection = phase3ActiveConnection($platform, 'merchant-evt-1');

    Event::assertDispatched(PlatformWebhookReceived::class, fn ($event) => $event->topic === 'app.store.authorize'
        && $event->storeId === 'merchant-evt-1');
    Event::assertDispatched(StoreProvisioned::class);
    Event::assertDispatched(TokensRefreshed::class, fn ($event) => $event->connection->id === $connection->id);
});

test('the received event carries the connection when the delivery resolved one', function () {
    Event::fake([PlatformWebhookReceived::class]);
    Mail::fake();
    Bus::fake();
    phase3FakeSalla('merchant-evt-1b');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-evt-1b'));

    $connection = phase3ActiveConnection($platform, 'merchant-evt-1b');
    $delivery = makeWebhookDelivery(makeWebhookEndpoint($platform), [
        'topic' => 'app.updated',
        'payload' => ['event' => 'app.updated', 'merchant' => $connection->external_store_id],
        'connection_id' => $connection->id,
    ]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));

    Event::assertDispatched(PlatformWebhookReceived::class, fn ($event) => $event->topic === 'app.updated'
        && $event->connection?->id === $connection->id
        && $event->storeId === $connection->external_store_id);
});

test('subscriptions fire SubscriptionChanged and call the bound applier', function () {
    Event::fake([SubscriptionChanged::class]);
    Mail::fake();
    Bus::fake();
    phase3FakeSalla('merchant-evt-2');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-evt-2'));

    $platform->planMappings()->create(['external_plan_key' => 'Growth', 'plan_id' => (string) Str::uuid()]);

    $connection = phase3ConnectionFor($platform, 'merchant-evt-2');
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint, [
        'topic' => 'app.subscription.started',
        'payload' => [
            'event' => 'app.subscription.started',
            'merchant' => 'merchant-evt-2',
            'created_at' => '2026-11-01 10:00:00',
            'data' => ['subscription_id' => 's1', 'item_type' => 'plan', 'plan_name' => 'Growth', 'end_date' => '2026-12-01 10:00:00'],
        ],
        'connection_id' => $connection->id,
    ]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));

    expect($this->applier()->calls)->toHaveCount(1);

    Event::assertDispatched(SubscriptionChanged::class, fn ($event) => $event->connection->id === $connection->id
        && $event->subscription->externalPlanKey === 'Growth');
});
