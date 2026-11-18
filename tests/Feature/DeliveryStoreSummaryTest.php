<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Support\StoreSummary;

function summaryStore(string $storeId = 'merchant-sum-1'): array
{
    Mail::fake();
    Bus::fake();
    phase3FakeSalla($storeId);
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload($storeId));

    $connection = phase3ConnectionFor($platform, $storeId)->refresh();
    $connection->storeSettings->forceFill([
        'metadata' => ['merchant' => ['avatar' => 'https://cdn.example.test/logo.png']],
    ])->save();

    return [$platform, $connection];
}

test('delivery rows carry the store card: logo, name, link and owner email', function () {
    [$platform, $connection] = summaryStore();
    $settings = $connection->storeSettings;

    $delivery = PlatformWebhookDelivery::query()->latest('received_at')->firstOrFail();
    $summaries = StoreSummary::forDeliveries(PlatformWebhookDelivery::query()->with('connection.storeSettings')->get());
    $store = $summaries[$delivery->id];

    expect($store['external_store_id'])->toBe('merchant-sum-1')
        ->and($store['logo_url'])->toBe('https://cdn.example.test/logo.png')
        ->and($store['name'])->toBe($settings->store_name)
        ->and($store['owner_email'])->toBe($settings->owner_email)
        ->and($store['url'])->not->toBeNull()
        ->and($store)->not->toHaveKey('tokens');
});

test('store summary resolves pre-connection deliveries through the payload store id', function () {
    [$platform, $connection] = summaryStore('merchant-sum-2');
    $delivery = PlatformWebhookDelivery::query()->latest('received_at')->firstOrFail();

    $summaries = StoreSummary::forDeliveries([$delivery->refresh()]);
    $store = $summaries[$delivery->id];

    expect($store['external_store_id'])->toBe('merchant-sum-2')
        ->and($store['owner_email'])->toBe($connection->storeSettings->owner_email);
});

test('processed authorize deliveries keep no tokens at rest', function () {
    summaryStore('merchant-sum-4');

    $delivery = PlatformWebhookDelivery::query()
        ->where('topic', 'app.store.authorize')
        ->latest('received_at')
        ->firstOrFail();

    expect($delivery->status->value)->toBe('processed')
        ->and($delivery->payload['data']['access_token'])->toBe('[REDACTED]')
        ->and($delivery->payload['data']['refresh_token'])->toBe('[REDACTED]')
        ->and($delivery->payload['merchant'])->toBe('merchant-sum-4');
});

test('a javascript: logo url never reaches the summary', function () {
    [$platform, $connection] = summaryStore('merchant-sum-3');
    $connection->storeSettings->forceFill([
        'metadata' => ['merchant' => ['avatar' => 'javascript:alert(1)']],
    ])->save();

    $delivery = PlatformWebhookDelivery::query()->latest('received_at')->firstOrFail();
    $summaries = StoreSummary::forDeliveries([$delivery]);
    $store = $summaries[$delivery->id];

    expect($store['logo_url'])->toBeNull();
});
