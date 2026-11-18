<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Omarsenusi\MarketplacePlatforms\Auth\PlatformApiClient;
use Omarsenusi\MarketplacePlatforms\Enums\AuthMode;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\User;

test('salla custom-mode callback exchanges code, provisions, and redirects unclaimed users to the claim page', function () {
    Mail::fake();
    phase3FakeSalla('merchant-10');
    $platform = phase3SallaPlatform(['auth_mode' => AuthMode::Custom]);

    $response = $this->get("/platforms/{$platform->slug}/callback?code=code-abc");

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toStartWith(url('/claim/'));

    $connection = phase3ConnectionFor($platform, 'merchant-10');
    expect($connection)->not->toBeNull();
    expect($connection->status)->toBe(ConnectionStatus::Active);
});

test('salla easy-mode platform rejects the callback with a friendly error page', function () {
    Mail::fake();
    phase3FakeSalla();
    $platform = phase3SallaPlatform(['auth_mode' => 'easy']);

    $response = $this->get("/platforms/{$platform->slug}/callback?code=code-abc");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('platforms/connect-error'));
});

test('callback without a code renders the connect-error page', function () {
    $platform = phase3SallaPlatform(['auth_mode' => AuthMode::Custom]);

    $response = $this->get("/platforms/{$platform->slug}/callback");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('platforms/connect-error'));
});

test('callback for an already-claimed owner logged in redirects to the dashboard', function () {
    Mail::fake();
    phase3FakeSalla('merchant-11');
    $platform = phase3SallaPlatform(['auth_mode' => AuthMode::Custom]);

    $this->get("/platforms/{$platform->slug}/callback?code=code-abc");

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();
    // Owner claims the account out-of-band.
    $user->forceFill(['password' => 'claimed-password-123', 'email_verified_at' => now()])->save();

    $response = $this->actingAs($user)->get("/platforms/{$platform->slug}/callback?code=code-abc");

    $response->assertRedirect(route('dashboard'));
});

test('callback for an already-claimed owner logged out redirects to login', function () {
    Mail::fake();
    phase3FakeSalla('merchant-12');
    $platform = phase3SallaPlatform(['auth_mode' => AuthMode::Custom]);

    $this->get("/platforms/{$platform->slug}/callback?code=code-abc");

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();
    $user->forceFill(['password' => 'claimed-password-123', 'email_verified_at' => now()])->save();

    $response = $this->get("/platforms/{$platform->slug}/callback?code=code-abc");

    $response->assertRedirect(route('login'));
});

test('callback with a tampered state is rejected when we issued one', function () {
    $platform = phase3SallaPlatform(['auth_mode' => AuthMode::Custom]);

    $response = $this->withSession(['platforms.oauth_state.'.$platform->slug => 'expected-state'])
        ->get("/platforms/{$platform->slug}/callback?code=code-abc&state=tampered");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('platforms/connect-error'));
});

test('zid callback redirects straight to the claim page for unclaimed users', function () {
    Mail::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform(['auth_mode' => AuthMode::OAuth]);

    $response = $this->get("/platforms/{$platform->slug}/callback?code=zid-code-1");

    $location = $response->headers->get('Location');
    expect($response->getStatusCode())->toBe(302);
    expect($location)->toStartWith(url('/claim/'));

    // Following the link shows the claim page with read-only owner data.
    $follow = $this->get(str_replace(url(''), '', $location));
    $follow->assertOk();
});

test('platform api client sends salla bearer and zid dual headers', function () {
    phase3FakeSalla('merchant-20');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-20'));

    phase3Refake([
        'api.salla.dev/*' => Http::response(['ok' => true], 200),
        'accounts.salla.sa/*' => Http::response(phase3SallaUserInfo('merchant-20'), 200),
    ]);

    $connection = phase3ConnectionFor($platform, 'merchant-20');
    $response = PlatformApiClient::for($connection)->get('/test');
    expect($response->successful())->toBeTrue();

    Http::assertSent(function ($sent) {
        return str_starts_with((string) $sent->url(), 'https://api.salla.dev')
            && str_starts_with($sent->header('Authorization')[0] ?? '', 'Bearer salla-access-')
            && ($sent->header('Accept-Language')[0] ?? '') !== '';
    });

    // Zid dual headers.
    phase3FakeZid();
    $zid = phase3ZidPlatform();
    phase3RunDelivery($zid, 'app.market.application.install', [
        'event' => 'app.market.application.install', 'store_id' => '424242', 'id' => 'evt-2',
    ]);
    $this->get("/platforms/{$zid->slug}/callback?code=zid-code-2");
    $zidConnection = phase3ConnectionFor($zid, '424242');

    phase3Refake(['api.zid.sa/*' => Http::response(['ok' => true], 200)]);
    PlatformApiClient::for($zidConnection)->get('/test');

    $recorded = Http::recorded();
    $last = $recorded->last()[0];
    expect($last->header('Authorization')[0] ?? '')->toBe('Bearer zid-auth-token');
    expect($last->header('X-Manager-Token')[0] ?? '')->toBe('zid-manager-token');
    expect($last->header('Accept-Language')[0] ?? '')->not->toBe('');
});
