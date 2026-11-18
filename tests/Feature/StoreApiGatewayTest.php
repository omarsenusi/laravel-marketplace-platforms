<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

function gatewayConnection(string $storeId = 'store-gw-1'): PlatformConnection
{
    // Fake the bus so the lane-budget math below measures only the
    // calls the test itself makes.
    Bus::fake();
    phase3FakeSalla($storeId);
    $platform = phase3SallaPlatform();

    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload($storeId));

    return phase3ActiveConnection($platform, $storeId);
}

function gatewayRefake(int $status = 200, array $body = ['ok' => true], array $headers = []): void
{
    phase3Refake([
        'api.salla.dev/*' => Http::response($body, $status, $headers),
    ]);
}

test('gateway returns success and caches headroom headers', function () {
    $connection = gatewayConnection('store-gw-2');
    gatewayRefake(200, ['data' => ['id' => 1]], [
        'X-RateLimit-Limit' => '120',
        'X-RateLimit-Remaining' => '110',
    ]);

    $result = app(StoreApiGateway::class)->get($connection, '/products');

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(200)
        ->and($result->json)->toBe(['data' => ['id' => 1]])
        ->and($result->deferred)->toBeFalse();

    $headroom = Cache::get("platforms:api:{$connection->id}:headroom");
    expect($headroom['remaining'])->toBe(110)
        ->and($headroom['limit'])->toBe(120);
});

test('gateway caches successful responses when cacheSeconds is set', function () {
    $connection = gatewayConnection('store-gw-3');
    gatewayRefake(200, ['data' => ['id' => 7]]);

    $gateway = app(StoreApiGateway::class);

    $first = $gateway->get($connection, '/products', ['page' => 1], 'chat', 60);
    $second = $gateway->get($connection, '/products', ['page' => 1], 'chat', 60);

    expect($first->ok)->toBeTrue()->and($second->ok)->toBeTrue();

    // Two gateway calls, one HTTP request — the second came from cache.
    Http::assertSentCount(1);
});

test('sync lane defers once 30 percent of the budget is used', function () {
    config(['platforms.api.rate_limits.salla' => 10]);

    $connection = gatewayConnection('store-gw-4');
    gatewayRefake();

    $gateway = app(StoreApiGateway::class);

    // 30% of 10 = 3 requests allowed for the sync lane.
    expect($gateway->get($connection, '/a', [], 'sync')->ok)->toBeTrue();
    expect($gateway->get($connection, '/b', [], 'sync')->ok)->toBeTrue();
    expect($gateway->get($connection, '/c', [], 'sync')->ok)->toBeTrue();

    $deferred = $gateway->get($connection, '/d', [], 'sync');

    expect($deferred->deferred)->toBeTrue()
        ->and($deferred->ok)->toBeFalse()
        ->and($deferred->retryAfter)->toBeGreaterThanOrEqual(0);

    // The deferred call made no HTTP request.
    Http::assertSentCount(3);

    // The chat lane has its own budget and still works.
    expect($gateway->get($connection, '/e', [], 'chat')->ok)->toBeTrue();
    Http::assertSentCount(4);
});

test('sync lane defers when headroom remaining is below 40 percent', function () {
    $connection = gatewayConnection('store-gw-5');

    // Record low headroom via a chat call (10/100 remaining).
    gatewayRefake(200, ['ok' => true], [
        'X-RateLimit-Limit' => '100',
        'X-RateLimit-Remaining' => '10',
    ]);

    $gateway = app(StoreApiGateway::class);
    expect($gateway->get($connection, '/products', [], 'chat')->ok)->toBeTrue();

    $deferred = $gateway->get($connection, '/products', [], 'sync');

    expect($deferred->deferred)->toBeTrue();

    // Only the chat call hit HTTP.
    Http::assertSentCount(1);
});

test('a 429 opens the breaker and the next call makes no http request', function () {
    $connection = gatewayConnection('store-gw-6');
    // Retry-After: 0 keeps PlatformApiClient's own single retry from
    // sleeping in the suite; the gateway still sees the final 429.
    gatewayRefake(429, ['error' => 'rate limited'], ['Retry-After' => '0']);

    $gateway = app(StoreApiGateway::class);

    $first = $gateway->get($connection, '/products');
    expect($first->deferred)->toBeTrue();

    // Chat lane fails fast: no in-request Retry-After sleep/retry.
    Http::assertSentCount(1);

    $countAfterFirst = count(Http::recorded());

    $second = $gateway->get($connection, '/products');
    expect($second->deferred)->toBeTrue()
        ->and($second->retryAfter)->toBeGreaterThan(0);

    // Breaker open: no new HTTP request.
    expect(count(Http::recorded()))->toBe($countAfterFirst);
});

test('a 404 does not open the breaker', function () {
    $connection = gatewayConnection('store-gw-7');
    gatewayRefake(404, ['error' => 'not found']);

    $gateway = app(StoreApiGateway::class);

    $first = $gateway->get($connection, '/missing');
    expect($first->ok)->toBeFalse()
        ->and($first->status)->toBe(404)
        ->and($first->deferred)->toBeFalse();

    $second = $gateway->get($connection, '/missing');
    expect($second->ok)->toBeFalse()
        ->and($second->deferred)->toBeFalse();

    // Both calls reached HTTP — no breaker intervened.
    Http::assertSentCount(2);
});

test('an inactive connection is refused without any http call', function () {
    $connection = gatewayConnection('store-gw-8');
    gatewayRefake();

    $connection->forceFill(['status' => ConnectionStatus::Uninstalled])->save();

    $result = app(StoreApiGateway::class)->get($connection->fresh(), '/products');

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('connection_inactive');

    Http::assertNothingSent();
});

test('a cached answer is served even while the breaker is open', function () {
    $connection = gatewayConnection('store-gw-cache-breaker');
    gatewayRefake(200, ['data' => [['id' => 1]]]);

    $gateway = app(StoreApiGateway::class);
    expect($gateway->get($connection, '/products', [], 'chat', 60)->ok)->toBeTrue();

    Cache::put("platforms:api:{$connection->id}:breaker", time() + 60, 60);
    $before = count(Http::recorded());

    $again = $gateway->get($connection, '/products', [], 'chat', 60);
    expect($again->ok)->toBeTrue()
        ->and(count(Http::recorded()))->toBe($before);
});

test('extra headers are sent', function () {
    $connection = gatewayConnection('store-gw-headers');
    gatewayRefake(200, ['data' => []]);

    app(StoreApiGateway::class)->get($connection, '/products', [], 'sync', 0, ['Store-Id' => 'abc']);

    Http::assertSent(fn ($request) => $request->hasHeader('Store-Id', 'abc'));
});
