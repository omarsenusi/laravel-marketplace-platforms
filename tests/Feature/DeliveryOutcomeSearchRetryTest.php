<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Services\WebhookDeliveryService;
use Omarsenusi\MarketplacePlatforms\Support\DeliverySearch;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;

/**
 * Live Salla subscription payload shape (captured from a demo store):
 * NO item_type / subscription_id, merchant is a JSON number, data.id is
 * the APP id.
 */
function liveSallaSubscriptionPayload(int $merchant, string $planName = 'pro'): array
{
    return [
        'event' => 'app.subscription.started',
        'merchant' => $merchant,
        'created_at' => '2026-11-13 02:04:44',
        'data' => [
            'id' => 103646732,
            'tax' => '.15',
            'price' => '39',
            'total' => '44.85',
            'app_name' => 'Demo Assistant',
            'app_type' => 'public',
            'end_date' => '2026-12-13 02:04:44',
            'features' => [],
            'plan_name' => $planName,
            'plan_type' => 'recurring',
            'plan_period' => '1',
            'subscription_at' => '2026-11-13 02:04:44',
            'start_date' => '2026-11-13 02:04:44',
        ],
    ];
}

function liveStore(int $merchant): array
{
    Mail::fake();
    Bus::fake();
    phase3FakeSalla((string) $merchant);
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload((string) $merchant));

    return [$platform, phase3ConnectionFor($platform, (string) $merchant)->refresh()];
}

function runLiveDelivery(Platform $platform, PlatformConnection $connection, array $payload): PlatformWebhookDelivery
{
    $delivery = makeWebhookDelivery(makeWebhookEndpoint($platform), [
        'topic' => $payload['event'],
        'payload' => $payload,
        'connection_id' => $connection->id,
    ]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));

    return $delivery->refresh();
}

test('a live salla subscription payload without item_type is applied and stores a snapshot', function () {
    [$platform, $connection] = liveStore(429050084);
    $platform->planMappings()->create(['external_plan_key' => 'pro', 'plan_id' => (string) Str::uuid()]);

    $delivery = runLiveDelivery($platform, $connection, liveSallaSubscriptionPayload(429050084));

    expect($delivery->status->value)->toBe('processed')
        ->and($delivery->outcome)->toStartWith('Applied:')
        // data.id is the app id — never stored as a subscription id.
        ->and($connection->refresh()->subscription['external_subscription_id'] ?? null)->toBeNull()
        ->and($connection->subscription['external_plan_key'])->toBe('pro');
});

test('an unmapped plan is processed but says so, and retrying after mapping applies it', function () {
    [$platform, $connection] = liveStore(429050085);

    $delivery = runLiveDelivery($platform, $connection, liveSallaSubscriptionPayload(429050085));

    expect($delivery->status->value)->toBe('processed')
        ->and($delivery->needs_action)->toBeTrue()
        ->and($delivery->outcome)->toContain('has no plan mapping');

    // Retryable through the service (no admin UI needed).
    expect(WebhookDeliveryService::canRetry($delivery))->toBeTrue();

    Bus::fake();
    expect(app(WebhookDeliveryService::class)->retry($delivery))->toBeTrue();
    Bus::assertDispatched(ProcessPlatformWebhook::class);

    expect($delivery->refresh()->status->value)->toBe('pending')
        ->and($delivery->refresh()->outcome)->toBeNull();

    $platform->planMappings()->create(['external_plan_key' => 'pro', 'plan_id' => (string) Str::uuid()]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));

    $delivery = $delivery->refresh();
    expect($delivery->outcome)->toStartWith('Applied:')
        ->and($delivery->needs_action)->toBeFalse()
        ->and($connection->refresh()->subscription['applied'])->toBeTrue();
});

test('a processed authorize whose tokens were scrubbed cannot be retried', function () {
    [$platform] = liveStore(429050086);
    $authorize = PlatformWebhookDelivery::query()->where('topic', 'app.store.authorize')->firstOrFail();

    expect(WebhookDeliveryService::canRetry($authorize))->toBeFalse();
    expect($authorize->refresh()->status->value)->toBe('processed');
});

test('store search finds deliveries by numeric store id, owner email and store link', function () {
    [$platform, $connection] = liveStore(429050087);
    runLiveDelivery($platform, $connection, liveSallaSubscriptionPayload(429050087));
    $settings = $connection->storeSettings;

    [, $other] = liveStore(511111111);

    $count = fn (string $term) => (function () use ($term) {
        $query = PlatformWebhookDelivery::query();
        DeliverySearch::apply($query, $term);

        return $query->count();
    })();

    expect($count('429050087'))->toBeGreaterThanOrEqual(2)
        ->and($count(strtoupper((string) $settings->owner_email)))->toBeGreaterThanOrEqual(2)
        ->and($count((string) $settings->store_url))->toBeGreaterThanOrEqual(2)
        ->and($count('nobody-matches-this'))->toBe(0);
});
