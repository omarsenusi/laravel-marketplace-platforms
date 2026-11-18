<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;

test('post sends a json body to the exact path and surfaces pacing headers', function () {
    $connection = commerceConnection('gw-post-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 5], 201, [
            'X-RateLimit-Remaining' => '99',
            'Retry-After' => '0',
        ]),
    ]);

    $result = app(StoreApiGateway::class)->post($connection, '/products', ['name' => 'Abaya', 'price' => 96.33]);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(201)
        ->and($result->json['data'])->toBe(['id' => 5])
        ->and($result->rateLimitRemaining)->toBe(99)
        ->and($result->retryAfterHeader)->toBe(0);

    $request = Http::recorded()[0][0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/products')
        ->and($request->data())->toBe(['name' => 'Abaya', 'price' => 96.33]);
});

test('put patch and delete hit their exact paths', function () {
    $connection = commerceConnection('gw-verbs-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 1]),
    ]);

    $gateway = app(StoreApiGateway::class);
    expect($gateway->put($connection, '/products/1', ['name' => 'X'])->ok)->toBeTrue();
    expect($gateway->patch($connection, '/shipping/routes/2', ['name' => 'Y'])->ok)->toBeTrue();
    expect($gateway->delete($connection, '/products/1')->ok)->toBeTrue();

    $recorded = Http::recorded();
    expect($recorded[0][0]->method())->toBe('PUT')
        ->and($recorded[0][0]->url())->toBe('https://api.salla.dev/admin/v2/products/1')
        ->and($recorded[1][0]->method())->toBe('PATCH')
        ->and($recorded[1][0]->url())->toBe('https://api.salla.dev/admin/v2/shipping/routes/2')
        ->and($recorded[2][0]->method())->toBe('DELETE')
        ->and($recorded[2][0]->url())->toBe('https://api.salla.dev/admin/v2/products/1');
});

test('a 401 refreshes the token once and retries the write once', function () {
    $connection = commerceConnection('gw-401-1');
    $oldToken = $connection->tokens['access_token'] ?? '';

    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::sequence([
            Http::response(['error' => ['code' => 'Unauthorized']], 401),
            commerceSallaOk(['id' => 9], 201),
        ]),
    ]);

    $result = app(StoreApiGateway::class)->post($connection, '/products', ['name' => 'Abaya']);

    expect($result->ok)->toBeTrue()->and($result->status)->toBe(201);

    // Exactly two store calls (401 + retry) plus the token refresh.
    $storeCalls = Http::recorded()->filter(
        static fn ($pair): bool => str_contains($pair[0]->url(), 'api.salla.dev'),
    )->values();
    expect($storeCalls->count())->toBe(2)
        ->and($storeCalls[0][0]->header('Authorization')[0] ?? '')->toBe('Bearer '.$oldToken)
        ->and($storeCalls[1][0]->header('Authorization')[0] ?? '')->toBe('Bearer salla-access-new');

    Http::assertSentCount(3);

    // The refreshed tokens were persisted on the connection.
    expect($connection->refresh()->tokens['access_token'] ?? null)->toBe('salla-access-new');
});

test('a write is never retried on 5xx', function () {
    $connection = commerceConnection('gw-5xx-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['error' => ['code' => 'upstream']], 500),
    ]);

    $result = app(StoreApiGateway::class)->post($connection, '/products', ['name' => 'Abaya']);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(500)
        ->and($result->error)->toBe('http_500')
        ->and($result->deferred)->toBeFalse();

    $storeCalls = Http::recorded()->filter(
        static fn ($pair): bool => str_contains($pair[0]->url(), 'api.salla.dev'),
    )->values();
    expect($storeCalls->count())->toBe(1);
});

test('a write is never retried on 429 and keeps the pacing headers', function () {
    $connection = commerceConnection('gw-429-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(
            ['status' => 429, 'success' => false, 'error' => ['code' => 'rate_limited', 'message' => 'slow down']],
            429,
            ['Retry-After' => '7', 'X-RateLimit-Remaining' => '0'],
        ),
    ]);

    $result = app(StoreApiGateway::class)->put($connection, '/products/1', ['name' => 'X']);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(429)
        ->and($result->retryAfterHeader)->toBe(7)
        ->and($result->rateLimitRemaining)->toBe(0);

    $storeCalls = Http::recorded()->filter(
        static fn ($pair): bool => str_contains($pair[0]->url(), 'api.salla.dev'),
    )->values();
    expect($storeCalls->count())->toBe(1);
});

test('write failures carry the error code and validation fields', function () {
    $connection = commerceConnection('gw-422-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaError(422, 'validation_failed', ['name' => ['The name field is required.']]),
    ]);

    $result = app(StoreApiGateway::class)->post($connection, '/products', []);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(422)
        ->and($result->errorCode)->toBe('validation_failed')
        ->and($result->fields)->toBe(['name' => ['The name field is required.']]);
});

test('lane shares are configurable under platforms.api.lanes', function () {
    config(['platforms.api.rate_limits.salla' => 10, 'platforms.api.lanes.background' => 0.90]);

    $connection = commerceConnection('gw-lanes-1');
    commerceRefake(['api.salla.dev/*' => Http::response(['ok' => true], 200)]);

    $gateway = app(StoreApiGateway::class);

    // 90% of 10 = 9 requests allowed on the background lane now.
    for ($i = 0; $i < 9; $i++) {
        expect($gateway->get($connection, "/b{$i}", [], 'background')->ok)->toBeTrue();
    }

    expect($gateway->get($connection, '/blocked', [], 'background')->deferred)->toBeTrue();
    Http::assertSentCount(9);
});

test('unknown lane names fall back to realtime', function () {
    $connection = commerceConnection('gw-lane-fallback-1');
    commerceRefake(['api.salla.dev/*' => Http::response(['ok' => true], 200)]);

    expect(app(StoreApiGateway::class)->get($connection, '/products', [], 'chat')->ok)->toBeTrue();
});
