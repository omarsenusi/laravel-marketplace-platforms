<?php

require_once __DIR__.'/WebhookHelpers.php';

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Omarsenusi\MarketplacePlatforms\Enums\DeliveryStatus;
use Omarsenusi\MarketplacePlatforms\Enums\PlatformStatus;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;

function ingressPost(string $slug, string $key, string $rawBody, array $headers = []): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', "/webhooks/platforms/{$slug}/{$key}", [], [], [], $server, $rawBody);
}

function sallaSignedBody(Platform $platform, array $payload): array
{
    $raw = json_encode($payload);
    $secret = $platform->secrets()->firstOrFail()->webhook_secret;

    return [$raw, ['X-Salla-Signature' => hash_hmac('sha256', $raw, $secret)]];
}

test('salla signed request stores a pending delivery and dispatches the job', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    [$raw, $headers] = sallaSignedBody($platform, ['event' => 'order.created', 'merchant' => 'store-9']);

    $response = ingressPost($platform->slug, $endpoint->key, $raw, $headers);

    $response->assertOk()->assertJson(['ok' => true]);

    $delivery = PlatformWebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->topic)->toBe('order.created')
        ->and($delivery->headers)->not->toContain('topsecret')
        ->and($delivery->headers['x-salla-signature'] ?? null)->toBe('[REDACTED]');

    Queue::assertPushed(ProcessPlatformWebhook::class);
});

test('salla token strategy (plain authorization header) is accepted', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    $raw = json_encode(['event' => 'app.store.authorize', 'merchant' => 'store-1']);

    ingressPost($platform->slug, $endpoint->key, $raw, ['Authorization' => 'test-webhook-secret'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(PlatformWebhookDelivery::query()->count())->toBe(1);
});

test('bad signature yields 401 and a rejected delivery, no job', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    $raw = json_encode(['event' => 'order.created', 'merchant' => 'store-1']);

    ingressPost($platform->slug, $endpoint->key, $raw, ['X-Salla-Signature' => 'wrong'])
        ->assertStatus(401)
        ->assertJson(['ok' => false]);

    $delivery = PlatformWebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Rejected)
        ->and($delivery->failure_reason)->not->toBeNull();

    Queue::assertNotPushed(ProcessPlatformWebhook::class);
});

test('duplicate body returns duplicate:true without a second job', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    [$raw, $headers] = sallaSignedBody($platform, ['event' => 'order.created', 'merchant' => 'store-1']);

    ingressPost($platform->slug, $endpoint->key, $raw, $headers)->assertOk();
    ingressPost($platform->slug, $endpoint->key, $raw, $headers)
        ->assertOk()
        ->assertJson(['ok' => true, 'duplicate' => true]);

    expect(PlatformWebhookDelivery::query()->count())->toBe(1);

    Queue::assertPushed(ProcessPlatformWebhook::class, 1);
});

test('unknown platform, endpoint, disabled endpoint, and archived platform are 404', function () {
    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    [$raw, $headers] = sallaSignedBody($platform, ['event' => 'x']);

    ingressPost('nope', $endpoint->key, $raw, $headers)->assertNotFound();
    ingressPost($platform->slug, 'nope', $raw, $headers)->assertNotFound();

    $endpoint->update(['enabled' => false]);
    ingressPost($platform->slug, $endpoint->key, $raw, $headers)->assertNotFound();
    $endpoint->update(['enabled' => true]);

    $platform->update(['status' => PlatformStatus::Archived]);
    ingressPost($platform->slug, $endpoint->key, $raw, $headers)->assertNotFound();
});

test('invalid json yields 400 with a rejected delivery', function () {
    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    ingressPost($platform->slug, $endpoint->key, '{broken', ['Authorization' => 'test-webhook-secret'])
        ->assertStatus(400);
    ingressPost($platform->slug, $endpoint->key, '{broken', ['Authorization' => 'test-webhook-secret'])
        ->assertStatus(400);

    $delivery = PlatformWebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Rejected)
        ->and($delivery->failure_reason)->toBe('invalid_json')
        ->and(PlatformWebhookDelivery::query()->count())->toBe(2);
});

test('a rejected forgery does not swallow the genuine delivery of the same event', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);
    [$raw, $headers] = sallaSignedBody($platform, ['event' => 'app.store.authorize', 'merchant' => 'store-9']);

    ingressPost($platform->slug, $endpoint->key, $raw, ['X-Salla-Signature' => 'forged'])->assertStatus(401);
    ingressPost($platform->slug, $endpoint->key, $raw, ['X-Salla-Signature' => 'forged'])->assertStatus(401);

    ingressPost($platform->slug, $endpoint->key, $raw, $headers)
        ->assertOk()
        ->assertJsonMissing(['duplicate' => true]);

    Queue::assertPushed(ProcessPlatformWebhook::class, 1);
});

test('uppercase hex signatures are accepted', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);
    [$raw, $headers] = sallaSignedBody($platform, ['event' => 'order.created', 'merchant' => 'store-1']);

    ingressPost($platform->slug, $endpoint->key, $raw, ['X-Salla-Signature' => strtoupper($headers['X-Salla-Signature'])])
        ->assertOk();
});

test('topic filter mismatch stores an ignored delivery', function () {
    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform, ['topic_filter' => ['order.created']]);

    [$raw, $headers] = sallaSignedBody($platform, ['event' => 'product.updated', 'merchant' => 's1']);

    ingressPost($platform->slug, $endpoint->key, $raw, $headers)
        ->assertOk()
        ->assertJson(['ok' => true, 'ignored' => true]);

    expect(PlatformWebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Ignored);
});

test('connection_id is resolved when a matching connection exists', function () {
    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    $connection = PlatformConnection::factory()->create([
        'platform_id' => $platform->id,
        'external_store_id' => 'store-42',
    ]);

    [$raw, $headers] = sallaSignedBody($platform, ['event' => 'order.created', 'merchant' => 'store-42']);

    ingressPost($platform->slug, $endpoint->key, $raw, $headers)->assertOk();

    expect(PlatformWebhookDelivery::query()->firstOrFail()->connection_id)->toBe($connection->id);
});

test('zid static api-key header is accepted and signature stored redacted', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'zid']);
    $endpoint = makeWebhookEndpoint($platform);

    $raw = json_encode(['event' => 'order.created', 'store_id' => 'z1', 'id' => 'evt-z1']);

    ingressPost($platform->slug, $endpoint->key, $raw, ['X-Zid-Api-Key' => 'test-partner-key'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $delivery = PlatformWebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->external_event_id)->toBe('evt-z1')
        ->and($delivery->headers['x-zid-api-key'] ?? null)->toBe('[REDACTED]');
});

test('ingress needs no session or csrf token', function () {
    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    [$raw, $headers] = sallaSignedBody($platform, ['event' => 'order.created']);

    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    // Raw call: no actingAs, no CSRF, no session cookies.
    $response = $this->call('POST', "/webhooks/platforms/{$platform->slug}/{$endpoint->key}", [], [], [], $server, $raw);

    $response->assertOk();
});
