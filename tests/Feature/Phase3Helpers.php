<?php

require_once __DIR__.'/WebhookHelpers.php';

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Enums\AuthMode;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Enums\PlatformStatus;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;

function phase3SallaPlatform(array $overrides = []): Platform
{
    $platform = makeWebhookPlatform(array_merge([
        'driver' => 'salla',
        'slug' => 'salla-test-'.str()->random(6),
        'status' => PlatformStatus::Active,
    ], $overrides));

    return $platform->fresh();
}

function phase3ZidPlatform(array $overrides = []): Platform
{
    $platform = makeWebhookPlatform(array_merge([
        'driver' => 'zid',
        'slug' => 'zid-test-'.str()->random(6),
        'status' => PlatformStatus::Active,
        'auth_mode' => AuthMode::OAuth,
    ], $overrides));

    return $platform->fresh();
}

function phase3SallaAuthorizePayload(string $storeId = 'merchant-1', ?int $expires = null): array
{
    return [
        'event' => 'app.store.authorize',
        'merchant' => $storeId,
        'data' => [
            'access_token' => 'salla-access-'.str()->random(8),
            'refresh_token' => 'salla-refresh-'.str()->random(8),
            'expires' => $expires ?? (time() + 3600),
            'scope' => 'offline_access',
        ],
    ];
}

function phase3SallaUserInfo(string $storeId = 'merchant-1'): array
{
    return [
        'data' => [
            'name' => 'Sara Store',
            'email' => 'sara@sara-store.sa',
            'mobile' => '+966500000001',
            'merchant' => [
                'id' => $storeId,
                'name' => 'Sara Store',
                'username' => 'sara-store',
                'domain' => 'sara-store.salla.sa',
                'currency' => 'SAR',
            ],
        ],
    ];
}

function phase3ZidProfile(): array
{
    return [
        'user' => [
            'name' => 'Zaid Owner',
            'email' => 'zaid@zaid-store.sa',
            'mobile' => '+966500000002',
        ],
        'store' => [
            'id' => 424242,
            'uuid' => 'store-uuid-1',
            'name' => 'Zaid Store',
            'url' => 'https://zaid.myzid.shop',
            'currency' => 'SAR',
        ],
    ];
}

function phase3FakeSalla(?string $storeId = 'merchant-1'): void
{
    Http::fake([
        'accounts.salla.sa/oauth2/user/info*' => Http::response(phase3SallaUserInfo($storeId ?? 'merchant-1'), 200),
        'accounts.salla.sa/oauth2/token' => Http::response([
            'access_token' => 'salla-access-new',
            'refresh_token' => 'salla-refresh-new',
            'expires' => time() + 7200,
            'scope' => 'offline_access',
        ], 200),
        '*' => Http::response([], 404),
    ]);
}

function phase3FakeZid(): void
{
    Http::fake([
        'oauth.zid.sa/oauth/token' => Http::response([
            'access_token' => 'zid-manager-token',
            'authorization' => 'zid-auth-token',
            'refresh_token' => 'zid-refresh-token',
            'expires_in' => 3600,
        ], 200),
        'api.zid.sa/v1/managers/account/profile*' => Http::response(phase3ZidProfile(), 200),
        'api.zid.sa/v1/managers/account/store*' => Http::response(['store' => ['id' => 424242, 'name' => 'Zaid Store']], 200),
        'api.zid.sa/v1/managers/embedded-apps-token*' => Http::response(['ok' => true], 200),
        '*' => Http::response([], 404),
    ]);
}

function phase3RunDelivery(Platform $platform, string $topic, array $payload): void
{
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint, [
        'topic' => $topic,
        'payload' => $payload,
    ]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));
}

function phase3ConnectionFor(Platform $platform, string $storeId): ?PlatformConnection
{
    return PlatformConnection::query()
        ->where('platform_id', $platform->id)
        ->where('external_store_id', $storeId)
        ->first();
}

/**
 * Http::fake() MERGES stubs (first match wins), so re-faking the same
 * URL later never takes effect. Swap in a fresh factory first when a
 * test needs different responses than the delivery phase used.
 *
 * @param  array<string, mixed>  $stubs
 */
function phase3Refake(array $stubs): void
{
    Http::swap(new Factory);
    Http::fake($stubs);
}

function phase3ActiveConnection(Platform $platform, string $storeId): PlatformConnection
{
    $connection = phase3ConnectionFor($platform, $storeId);
    expect($connection)->not->toBeNull();
    expect($connection->status)->toBe(ConnectionStatus::Active);

    return $connection;
}
