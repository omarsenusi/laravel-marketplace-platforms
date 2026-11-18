<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Omarsenusi\MarketplacePlatforms\Auth\TokenStore;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Jobs\RefreshPlatformToken;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Workspace;

test('refresh job persists both tokens and reschedules with jitter', function () {
    phase3FakeSalla('merchant-30');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-30'));

    $connection = phase3ConnectionFor($platform, 'merchant-30');
    $firstScheduled = $connection->refresh_scheduled_at;

    phase3Refake([
        'accounts.salla.sa/oauth2/token' => Http::response([
            'access_token' => 'salla-access-refreshed',
            'refresh_token' => 'salla-refresh-rotated',
            'expires_in' => 7200,
            'scope' => 'offline_access',
        ], 200),
    ]);

    (new RefreshPlatformToken($connection->id))->handle(app(TokenStore::class));

    $connection = phase3ConnectionFor($platform, 'merchant-30');
    expect($connection->tokens['access_token'] ?? null)->toBe('salla-access-refreshed');
    // Single-use rotation: the new refresh token replaced the old one.
    expect($connection->tokens['refresh_token'] ?? null)->toBe('salla-refresh-rotated');
    expect($connection->status)->toBe(ConnectionStatus::Active);
    // 2h token with a 72h lead: the lead is capped at 90% of the
    // lifetime, so the refresh lands ~12 min out (minus ≤5% jitter).
    expect($connection->token_expires_at->getTimestamp())
        ->toBeGreaterThanOrEqual(time() + 7200 - 5);
    expect($connection->refresh_scheduled_at->getTimestamp())
        ->toBeGreaterThanOrEqual(time() + 720 - 324 - 5)
        ->toBeLessThanOrEqual(time() + 720 + 5);
    expect($connection->refresh_scheduled_at->eq($firstScheduled))->toBeFalse();
});

test('invalid_grant flips the connection to requires_reauth immediately', function () {
    phase3FakeSalla('merchant-31');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-31'));

    $connection = phase3ConnectionFor($platform, 'merchant-31');

    phase3Refake([
        'accounts.salla.sa/oauth2/token' => Http::response(['error' => 'invalid_grant'], 401),
    ]);

    (new RefreshPlatformToken($connection->id))->handle(app(TokenStore::class));

    $connection = phase3ConnectionFor($platform, 'merchant-31');
    expect($connection->status)->toBe(ConnectionStatus::RequiresReauth);
    expect($connection->refresh_scheduled_at)->toBeNull();
});

test('refresh lock prevents concurrent double refresh', function () {
    phase3FakeSalla('merchant-32');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-32'));

    $connection = phase3ConnectionFor($platform, 'merchant-32');

    $calls = 0;
    phase3Refake([
        'accounts.salla.sa/oauth2/token' => function () use (&$calls) {
            $calls++;

            return Http::response([
                'access_token' => 'tok-'.$calls,
                'refresh_token' => 'ref-'.$calls,
                'expires_in' => 7200,
            ], 200);
        },
    ]);

    $lock = Cache::lock("platforms:refresh:{$connection->id}", 60);
    $lock->acquire();
    config(['platforms.refresh.lock_wait_seconds' => 0]);

    try {
        // With the lock held elsewhere, the job requeues instead of refreshing.
        Queue::fake();
        (new RefreshPlatformToken($connection->id))->handle(app(TokenStore::class));
        expect($calls)->toBe(0);
        Queue::assertPushed(RefreshPlatformToken::class);
    } finally {
        $lock->release();
    }
});

test('due command dispatches only due active connections', function () {
    phase3FakeSalla('merchant-33');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-33'));

    $due = phase3ConnectionFor($platform, 'merchant-33');
    $due->forceFill(['refresh_scheduled_at' => now()->subMinute()])->save();

    $futureWorkspace = Workspace::factory()->create();
    $future = PlatformConnection::create([
        'platform_id' => $platform->id,
        'workspace_id' => $futureWorkspace->id,
        'external_store_id' => 'merchant-34',
        'status' => ConnectionStatus::Active,
        'refresh_scheduled_at' => now()->addDay(),
    ]);

    Queue::fake();

    $this->artisan('platforms:tokens:refresh-due')->assertSuccessful();

    Queue::assertPushed(RefreshPlatformToken::class, function ($job) use ($due) {
        return $job->connectionId === $due->id;
    });
    Queue::assertNotPushed(RefreshPlatformToken::class, function ($job) use ($future) {
        return $job->connectionId === $future->id;
    });
});

test('refresh response with absolute expires still schedules', function () {
    phase3FakeSalla('merchant-35');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-35'));

    $connection = phase3ConnectionFor($platform, 'merchant-35');

    phase3Refake([
        'accounts.salla.sa/oauth2/token' => Http::response([
            'access_token' => 'a2',
            'refresh_token' => 'r2',
            'expires' => time() + 5000,
        ], 200),
    ]);

    (new RefreshPlatformToken($connection->id))->handle(app(TokenStore::class));

    $connection = phase3ConnectionFor($platform, 'merchant-35');
    expect($connection->token_expires_at->getTimestamp())->toBeGreaterThan(time() + 4000);
});

test('null-password user cannot log in, and failure is a clean credentials error', function () {
    phase3FakeSalla('merchant-36');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-36'));

    $response = $this->post('/login', [
        'email' => 'sara@sara-store.sa',
        'password' => 'anything-at-all',
    ]);

    // No 500: the login attempt fails with a generic credentials error.
    expect($response->getStatusCode())->toBe(302);
    $response->assertSessionHasErrors();
    expect(auth()->check())->toBeFalse();
});

test('a 14-day salla token is refreshed 72h before expiry by default (≤1h jitter)', function () {
    phase3FakeSalla('merchant-sched-1');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-sched-1', time() + 14 * 86400));

    $connection = phase3ConnectionFor($platform, 'merchant-sched-1');

    expect(TokenStore::leadHours($platform))->toBe(72);

    $expected = $connection->token_expires_at->getTimestamp() - 72 * 3600;
    $actual = $connection->refresh_scheduled_at->getTimestamp();

    expect($actual)->toBeLessThanOrEqual($expected)
        ->toBeGreaterThanOrEqual($expected - 3600);
});
