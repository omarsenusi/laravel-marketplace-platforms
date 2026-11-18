<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Jobs\RegisterZidEmbeddedToken;
use Omarsenusi\MarketplacePlatforms\Mail\ClaimWorkspaceMail;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\User;

function phase4ProvenSalla(string $storeId = 'merchant-emb-1'): array
{
    Mail::fake();
    Bus::fake();
    phase3FakeSalla($storeId);
    $platform = phase3SallaPlatform(['app_id' => 'salla-app-123']);
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload($storeId));

    return [$platform, phase3ConnectionFor($platform, $storeId)->refresh()];
}

function phase4FakeSallaIntrospect(string $storeId, ?string $exp = null): void
{
    // Fresh factory: Http::fake() appends stubs (first match wins),
    // so the phase3 catch-all would shadow this stub otherwise.
    Http::swap(new Factory);
    Http::fake([
        'api.salla.dev/exchange-authority/v1/introspect*' => Http::response([
            'success' => true,
            'data' => array_filter([
                'merchant_id' => $storeId,
                'exp' => $exp ?? now()->addHour()->toIso8601String(),
            ]),
        ], 200),
    ]);
}

test('salla launch with a valid embedded token renders the claim page without emailing', function () {
    [$platform, $connection] = phase4ProvenSalla();
    phase4FakeSallaIntrospect($connection->external_store_id);

    $response = $this->get("/platforms/{$platform->slug}/launch?token=short-lived-token");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('platforms/launch-embedded')
        ->where('mode', 'claim')
        ->where('url', fn ($url) => is_string($url) && str_contains($url, '/claim/')));

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();
    expect(WorkspaceClaim::query()->where('user_id', $user->id)->open()->count())->toBe(1);

    // Proven launch never emails — the verified merchant gets the
    // claim URL in the page props (asserted above) instead.
    Mail::assertNotSent(ClaimWorkspaceMail::class);

    // Salla received the app id as S-Source.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.salla.dev/exchange-authority/v1/introspect'
        && $request->hasHeader('S-Source', 'salla-app-123'));
});

test('salla launch with a valid token for a claimed owner renders the dashboard', function () {
    [$platform, $connection] = phase4ProvenSalla('merchant-emb-2');
    phase4FakeSallaIntrospect($connection->external_store_id);

    User::query()->where('email', 'sara@sara-store.sa')->firstOrFail()
        ->forceFill(['password' => 'claimed-password-123'])->save();

    $response = $this->get("/platforms/{$platform->slug}/launch?token=tok-2");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('platforms/launch-embedded')
        ->where('mode', 'dashboard'));

    Mail::assertNotSent(ClaimWorkspaceMail::class);
});

test('salla launch ignores a disagreeing store_id when the token is proven', function () {
    [$platform, $connection] = phase4ProvenSalla('merchant-emb-3');
    phase4FakeSallaIntrospect($connection->external_store_id);

    $response = $this->get("/platforms/{$platform->slug}/launch?token=tok-3&store_id=someone-else");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('platforms/launch-embedded')
        ->where('mode', 'claim'));
});

test('salla launch with a rejected token falls back without a claim url', function () {
    [$platform] = phase4ProvenSalla('merchant-emb-4');
    Http::swap(new Factory);
    Http::fake([
        'api.salla.dev/exchange-authority/v1/introspect*' => Http::response(['success' => false], 401),
    ]);

    $response = $this->get('/platforms/'.$platform->slug.'/launch?token=bogus&store_id=merchant-emb-4');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('platforms/launch'));
    expect($response->getContent())->not->toContain('/claim/');
});

test('salla launch with an expired token falls back', function () {
    [$platform] = phase4ProvenSalla('merchant-emb-5');
    phase4FakeSallaIntrospect('merchant-emb-5', now()->subHour()->toIso8601String());

    $response = $this->get('/platforms/'.$platform->slug.'/launch?token=expired&store_id=merchant-emb-5');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('platforms/launch'));
    expect($response->getContent())->not->toContain('/claim/');
});

test('salla launch with a token but no store resolves to connect-error, not 404', function () {
    [$platform] = phase4ProvenSalla('merchant-emb-6');
    Http::swap(new Factory);
    Http::fake([
        'api.salla.dev/exchange-authority/v1/introspect*' => Http::response(['success' => false], 401),
    ]);

    $this->get('/platforms/'.$platform->slug.'/launch?token=bogus')->assertOk()
        ->assertInertia(fn ($page) => $page->component('platforms/connect-error'));
});

test('salla launch with a missing app id cannot prove the token', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeSalla('merchant-emb-7');
    $platform = phase3SallaPlatform(['app_id' => null]);
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-emb-7'));

    $this->get('/platforms/'.$platform->slug.'/launch?token=tok-7&store_id=merchant-emb-7')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('platforms/launch'));
});

test('zid embedded token registration posts the uuid and stores its hash', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-1',
    ]);
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-emb-1")->assertRedirect();

    $connection = phase3ActiveConnection($platform, '424242');

    $sent = [];
    Http::swap(new Factory);
    Http::fake([
        'api.zid.sa/v1/managers/embedded-apps-token*' => Http::response(['ok' => true], 200),
    ]);

    (new RegisterZidEmbeddedToken($connection->id))->handle();

    Http::assertSent(function ($request) use (&$sent) {
        if (! str_contains($request->url(), 'embedded-apps-token')) {
            return false;
        }

        $sent = $request->data();

        return $request->hasHeader('Authorization')
            && $request->hasHeader('X-Manager-Token', 'zid-manager-token')
            && str_starts_with((string) $request->header('Authorization')[0], 'Bearer ');
    });

    expect($sent['token'] ?? null)->not->toBeNull();
    expect((bool) Str::isUuid($sent['token']))->toBeTrue();

    $hash = $connection->refresh()->embedded_token_hash;
    expect($hash)->toBe(hash('sha256', $sent['token']));
});

test('zid embedded token registration stops on 403 without storing', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-1',
    ]);
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-emb-2")->assertRedirect();

    $connection = phase3ActiveConnection($platform, '424242');

    Http::swap(new Factory);
    Http::fake([
        'api.zid.sa/v1/managers/embedded-apps-token*' => Http::response(['message' => 'forbidden'], 403),
    ]);

    (new RegisterZidEmbeddedToken($connection->id))->handle();

    expect($connection->refresh()->embedded_token_hash)->toBeNull();
});

test('zid launch with a registered token is proven', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-1',
    ]);
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-emb-3")->assertRedirect();

    $connection = phase3ActiveConnection($platform, '424242');
    $connection->forceFill(['embedded_token_hash' => hash('sha256', 'zid-launch-uuid')])->save();

    $response = $this->get("/platforms/{$platform->slug}/launch?token=zid-launch-uuid");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('platforms/launch-embedded')
        ->where('mode', 'claim'));
    Mail::assertNotSent(ClaimWorkspaceMail::class);
});

test('zid launch with an unknown token falls back', function () {
    Mail::fake();
    Bus::fake();
    phase3FakeZid();
    $platform = phase3ZidPlatform();

    phase3RunDelivery($platform, 'app.market.application.install', [
        'event' => 'app.market.application.install',
        'store_id' => '424242',
        'id' => 'evt-1',
    ]);
    $this->get("/platforms/{$platform->slug}/callback?code=auth-code-emb-4")->assertRedirect();

    $response = $this->get("/platforms/{$platform->slug}/launch?token=random-token");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('platforms/connect-error'));
    expect($response->getContent())->not->toContain('/claim/');
});

test('launch responses carry the configured frame ancestors', function () {
    [$platform, $connection] = phase4ProvenSalla('merchant-emb-9');
    phase4FakeSallaIntrospect($connection->external_store_id);

    config(['platforms.embedded.frame_ancestors' => 'self https://frames.example.test']);

    $response = $this->get("/platforms/{$platform->slug}/launch?token=tok-9");

    $response->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain('frame-ancestors self https://frames.example.test');
    expect($response->headers->has('X-Frame-Options'))->toBeFalse();
});
