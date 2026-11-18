<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Mail\ClaimWorkspaceMail;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Services\Uninstaller;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;

function reviewSallaStore(string $storeId, ?Platform $platform = null): array
{
    Mail::fake();
    Bus::fake();
    phase3FakeSalla($storeId);
    $platform ??= phase3SallaPlatform(['app_id' => 'salla-app-123']);
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload($storeId));

    return [$platform, phase3ConnectionFor($platform, $storeId)->refresh()];
}

function reviewRunTopic(Platform $platform, PlatformConnection $connection, string $topic, array $payload): void
{
    $delivery = makeWebhookDelivery(makeWebhookEndpoint($platform), [
        'topic' => $topic,
        'payload' => $payload,
        'connection_id' => $connection->id,
    ]);

    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));
}

test('default frame-ancestors quote self so the CSP keyword is valid', function () {
    expect((string) config('platforms.embedded.frame_ancestors'))->toStartWith("'self' ");
});

test('salla app.updated never resurrects an uninstalled store nor mails the owner', function () {
    [$platform, $connection] = reviewSallaStore('merchant-rev-1');
    app(Uninstaller::class)->uninstall($connection->refresh(), 'test');
    Mail::fake();

    reviewRunTopic($platform, $connection, 'app.updated', ['event' => 'app.updated', 'merchant' => 'merchant-rev-1']);

    $connection->refresh();
    expect($connection->uninstalled_at)->not->toBeNull();
    expect($connection->status)->toBe(ConnectionStatus::Uninstalled);
    Mail::assertNothingQueued();
});

test('salla app.updated on a live unclaimed store sends no claim mail', function () {
    [$platform, $connection] = reviewSallaStore('merchant-rev-2');
    // Burn the open claim so the "reissue" branch would fire.
    WorkspaceClaim::query()->update(['expires_at' => now()->subMinute()]);
    Mail::fake();

    reviewRunTopic($platform, $connection, 'app.updated', ['event' => 'app.updated', 'merchant' => 'merchant-rev-2']);

    Mail::assertNotQueued(ClaimWorkspaceMail::class);
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});

test('salla introspection without exp is not a proof', function () {
    [$platform, $connection] = reviewSallaStore('merchant-rev-7');
    Http::swap(new Factory);
    Http::fake([
        'api.salla.dev/exchange-authority/v1/introspect*' => Http::response([
            'success' => true,
            'data' => ['merchant_id' => 'merchant-rev-7'],
        ], 200),
    ]);

    $response = $this->get("/platforms/{$platform->slug}/launch?token=tok-noexp");

    $response->assertOk();
    expect($response->getContent())->not->toContain('launch-embedded');
});

test('uninstall clears the zid embedded launch token and a proven launch refuses uninstalled stores', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();
    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-rev',
    ]);
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-rev")->assertRedirect();

    $connection = phase3ActiveConnection($platform, '424242');
    $connection->forceFill(['embedded_token_hash' => hash('sha256', 'zid-rev-uuid')])->save();

    app(Uninstaller::class)->uninstall($connection->refresh(), 'test');

    expect($connection->refresh()->embedded_token_hash)->toBeNull();

    $response = $this->get("/platforms/{$platform->slug}/launch?token=zid-rev-uuid");
    expect($response->getContent())->not->toContain('/claim/');
});
