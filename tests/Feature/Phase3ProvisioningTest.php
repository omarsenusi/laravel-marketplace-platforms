<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Events\StoreProvisioned;
use Omarsenusi\MarketplacePlatforms\Events\StoreReinstalled;
use Omarsenusi\MarketplacePlatforms\Events\StoreUninstalled;
use Omarsenusi\MarketplacePlatforms\Mail\ClaimWorkspaceMail;
use Omarsenusi\MarketplacePlatforms\Mail\StoreConnectedMail;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\User;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Workspace;

test('salla authorize webhook provisions user, workspace, connection, encrypted tokens and claim mail', function () {
    Mail::fake();
    Event::fake([StoreProvisioned::class, StoreReinstalled::class]);
    phase3FakeSalla('merchant-1');
    $platform = phase3SallaPlatform();

    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-1'));

    $connection = phase3ActiveConnection($platform, 'merchant-1');

    // Encrypted tokens with ABSOLUTE expiry (expires=now+3600 passed).
    $raw = $connection->getAttributes()['tokens'];
    expect($raw)->not->toContain('salla-access');
    $tokens = $connection->tokens;
    expect($tokens['access_token'] ?? null)->toStartWith('salla-access-');
    expect($tokens['refresh_token'] ?? null)->toStartWith('salla-refresh-');
    expect($connection->token_expires_at)->not->toBeNull();
    expect($connection->token_expires_at->getTimestamp())->toBeGreaterThan(time() + 3000);
    expect($connection->refresh_scheduled_at)->not->toBeNull();

    // User: null password, phone stored, owner workspace + default workspace.
    $user = User::query()->where('email', 'sara@sara-store.sa')->first();
    expect($user)->not->toBeNull();
    expect($user->password)->toBeNull();
    expect($user->phone)->toBe('+966500000001');

    $workspace = Workspace::query()->findOrFail($connection->workspace_id);
    expect($workspace->owner_user_id)->toBe($user->id);
    expect($user->refresh()->default_workspace_id)->toBe($workspace->id);

    // Store settings from the real profile.
    expect($connection->storeSettings->store_name)->toBe('Sara Store');
    expect($connection->storeSettings->owner_email)->toBe('sara@sara-store.sa');

    Mail::assertQueued(ClaimWorkspaceMail::class, 1);

    // Pending install (no email yet) then reactivation with the owner.
    Event::assertDispatched(StoreProvisioned::class, fn ($event) => $event->connection->id === $connection->id);
    Event::assertDispatched(StoreReinstalled::class, fn ($event) => $event->connection->id === $connection->id);
});

test('salla authorize redelivery creates no duplicates and app.installed after authorize does not downgrade', function () {
    Mail::fake();
    phase3FakeSalla('merchant-9');
    $platform = phase3SallaPlatform();
    $payload = phase3SallaAuthorizePayload('merchant-9');

    phase3RunDelivery($platform, 'app.store.authorize', $payload);
    phase3RunDelivery($platform, 'app.store.authorize', $payload);
    phase3RunDelivery($platform, 'app.installed', ['event' => 'app.installed', 'merchant' => 'merchant-9']);

    expect(User::query()->where('email', 'sara@sara-store.sa')->count())->toBe(1);
    expect(PlatformConnection::query()
        ->where('platform_id', $platform->id)
        ->where('external_store_id', 'merchant-9')->count())->toBe(1);

    $connection = phase3ActiveConnection($platform, 'merchant-9');
    expect($connection->tokens['access_token'] ?? null)->not->toBeNull();
});

test('existing email user gets a new workspace and a login mail, never a new user', function () {
    Mail::fake();
    phase3FakeSalla('merchant-2');
    $platform = phase3SallaPlatform();

    $existing = User::factory()->create(['email' => 'sara@sara-store.sa', 'password' => 'existing-secret-123']);

    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-2'));

    expect(User::query()->where('email', 'sara@sara-store.sa')->count())->toBe(1);

    $connection = phase3ActiveConnection($platform, 'merchant-2');
    expect($connection->workspace_id)->not->toBeNull();

    $workspace = Workspace::query()->findOrFail($connection->workspace_id);
    expect($workspace->owner_user_id)->toBe($existing->id);

    Mail::assertQueued(StoreConnectedMail::class, 1);
    Mail::assertNotQueued(ClaimWorkspaceMail::class);
});

test('reinstall after uninstall reactivates the same connection, user and workspace', function () {
    Mail::fake();
    Event::fake([StoreReinstalled::class]);
    phase3FakeSalla('merchant-3');
    $platform = phase3SallaPlatform();

    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-3'));
    $connection = phase3ActiveConnection($platform, 'merchant-3');
    $userId = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail()->id;
    $workspaceId = $connection->workspace_id;

    phase3RunDelivery($platform, 'app.uninstalled', ['event' => 'app.uninstalled', 'merchant' => 'merchant-3']);

    $uninstalled = phase3ConnectionFor($platform, 'merchant-3');
    expect($uninstalled->status)->toBe(ConnectionStatus::Uninstalled);
    expect($uninstalled->tokens)->toBeNull();

    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-3'));

    $reactivated = phase3ActiveConnection($platform, 'merchant-3');
    expect($reactivated->id)->toBe($connection->id);
    expect($reactivated->workspace_id)->toBe($workspaceId);
    expect($reactivated->tokens['access_token'] ?? null)->not->toBeNull();
    expect(User::query()->where('email', 'sara@sara-store.sa')->firstOrFail()->id)->toBe($userId);

    Event::assertDispatched(StoreReinstalled::class, fn ($event) => $event->connection->id === $connection->id);
});

test('uninstall clears tokens, expires claims, keeps user and workspace, and fires the event', function () {
    Mail::fake();
    Event::fake([StoreUninstalled::class]);
    phase3FakeSalla('merchant-4');
    $platform = phase3SallaPlatform();

    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-4'));
    $connection = phase3ActiveConnection($platform, 'merchant-4');
    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();

    $openClaims = WorkspaceClaim::query()->where('user_id', $user->id)->whereNull('used_at')->count();
    expect($openClaims)->toBeGreaterThan(0);

    phase3RunDelivery($platform, 'app.uninstalled', ['event' => 'app.uninstalled', 'merchant' => 'merchant-4']);

    $connection = phase3ConnectionFor($platform, 'merchant-4');
    expect($connection->status)->toBe(ConnectionStatus::Uninstalled);
    expect($connection->uninstalled_at)->not->toBeNull();
    expect($connection->tokens)->toBeNull();
    expect($connection->token_expires_at)->toBeNull();
    expect($connection->refresh_scheduled_at)->toBeNull();

    // Claims expired (used_at stays null).
    expect(WorkspaceClaim::query()->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->count())->toBe(0);

    // User + workspace survive.
    expect(User::query()->find($user->id))->not->toBeNull();
    expect(Workspace::query()->find($connection->workspace_id))->not->toBeNull();

    Event::assertDispatched(StoreUninstalled::class, fn ($event) => $event->connection->id === $connection->id);
});

test('zid install webhook creates a pending connection without user, oauth callback attaches everything', function () {
    Mail::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-1',
    ]);

    $connection = phase3ConnectionFor($platform, '424242');
    expect($connection)->not->toBeNull();
    expect($connection->status)->toBe(ConnectionStatus::PendingOAuth);
    expect($connection->workspace_id)->toBeNull();
    expect(User::query()->where('email', 'zaid@zaid-store.sa')->count())->toBe(0);

    // OAuth callback with a code → token exchange mapping + profile fetch.
    $response = $this->get("/platforms/{$platform->slug}/callback?code=auth-code-123");

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith(url('/claim/'));

    $connection = phase3ActiveConnection($platform, '424242');

    // Zid dual mapping: manager_token + authorization_token stored.
    expect($connection->tokens['manager_token'] ?? null)->toBe('zid-manager-token');
    expect($connection->tokens['authorization_token'] ?? null)->toBe('zid-auth-token');
    expect($connection->tokens['refresh_token'] ?? null)->toBe('zid-refresh-token');

    $user = User::query()->where('email', 'zaid@zaid-store.sa')->firstOrFail();
    expect($connection->workspace->owner_user_id)->toBe($user->id);
    expect($connection->storeSettings->store_name)->toBe('Zaid Store');

    Mail::assertQueued(ClaimWorkspaceMail::class, 1);
});
