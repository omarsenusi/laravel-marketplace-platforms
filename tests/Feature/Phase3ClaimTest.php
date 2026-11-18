<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Events\WorkspaceClaimed;
use Omarsenusi\MarketplacePlatforms\Mail\ClaimWorkspaceMail;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\User;

function phase3ProvisionedClaim(string $storeId = 'merchant-50'): array
{
    Mail::fake();
    phase3FakeSalla($storeId);
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload($storeId));

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();
    $issued = app(ClaimService::class)->issueAndSend($user, phase3ConnectionFor($platform, $storeId), null);

    return [$user, $issued['token']];
}

test('claim show renders read-only owner data', function () {
    [$user, $token] = phase3ProvisionedClaim('merchant-50');

    $response = $this->get("/claim/{$token}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/claim-workspace')
        ->where('expired', false)
        ->where('owner.email', 'sara@sara-store.sa')
        ->where('maskedEmail', 's***@sara-store.sa'));
});

test('claim store sets password, verifies email, logs in, invalidates other claims and fires the event', function () {
    Mail::fake();
    Event::fake([WorkspaceClaimed::class]);
    phase3FakeSalla('merchant-51');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-51'));

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();
    $connection = phase3ConnectionFor($platform, 'merchant-51');

    $first = app(ClaimService::class)->issueAndSend($user, $connection, null);
    $second = app(ClaimService::class)->issueAndSend($user, $connection, null);

    $response = $this->post("/claim/{$second['token']}", [
        'password' => 'new-secure-password-123',
        'password_confirmation' => 'new-secure-password-123',
    ]);

    $response->assertRedirect('/dashboard');
    expect(auth()->check())->toBeTrue();

    $user = $user->refresh();
    expect(Hash::check('new-secure-password-123', $user->password))->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
    expect(WorkspaceClaim::query()->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->count())->toBe(0);

    Event::assertDispatched(WorkspaceClaimed::class, fn ($event) => $event->via === 'claim_page');
});

test('weak and mismatched passwords are rejected', function () {
    [$user, $token] = phase3ProvisionedClaim('merchant-52');

    $this->post("/claim/{$token}", [
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->post("/claim/{$token}", [
        'password' => 'new-secure-password-123',
        'password_confirmation' => 'different-password-456',
    ])->assertSessionHasErrors('password');

    expect($user->refresh()->password)->toBeNull();
});

test('expired token shows the expired state, never dead-ends', function () {
    [$user, $token] = phase3ProvisionedClaim('merchant-53');

    WorkspaceClaim::query()->where('token_hash', WorkspaceClaim::hashToken($token))->update(['expires_at' => now()->subHour()]);

    $response = $this->get("/claim/{$token}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/claim-workspace')
        ->where('expired', true));
});

test('resend works for expired tokens, is rate limited, and stays neutral', function () {
    Mail::fake();
    [$user, $token] = phase3ProvisionedClaim('merchant-54');

    WorkspaceClaim::query()->where('token_hash', WorkspaceClaim::hashToken($token))->update(['expires_at' => now()->subHour()]);

    $openBefore = WorkspaceClaim::query()->whereNull('used_at')->where('expires_at', '>', now())->count();

    $response = $this->from("/claim/{$token}")->post("/claim/{$token}/resend");

    // The browser is sent back to the OLD (expired) page — the fresh token
    // is only ever delivered by email, never to whoever holds the old link.
    $response->assertRedirect("/claim/{$token}");
    $response->assertSessionHas('status');
    expect(WorkspaceClaim::query()->whereNull('used_at')->where('expires_at', '>', now())->count())
        ->toBe($openBefore + 1);
    Mail::assertQueued(ClaimWorkspaceMail::class);

    // Exhaust the 3/hour budget (1 used above).
    $this->post("/claim/{$token}/resend");
    $this->post("/claim/{$token}/resend");
    $openAtLimit = WorkspaceClaim::query()->whereNull('used_at')->where('expires_at', '>', now())->count();
    $limited = $this->from("/claim/{$token}")->post("/claim/{$token}/resend");

    $limited->assertRedirect("/claim/{$token}");
    $limited->assertSessionHas('status');
    expect(WorkspaceClaim::query()->whereNull('used_at')->where('expires_at', '>', now())->count())
        ->toBe($openAtLimit);
});

test('claimed user opening an old link is sent to login', function () {
    [$user, $token] = phase3ProvisionedClaim('merchant-55');

    $user->forceFill(['password' => 'claimed-password-123'])->save();

    $response = $this->get("/claim/{$token}");

    $response->assertRedirect(route('login'));
});

test('unknown claim token is a 404', function () {
    $this->get('/claim/'.str_repeat('a', 64))->assertNotFound();
    $this->post('/claim/'.str_repeat('b', 64), [
        'password' => 'x',
        'password_confirmation' => 'x',
    ])->assertNotFound();
});

test('forgot-password reset works for unclaimed users and counts as claiming', function () {
    Event::fake([WorkspaceClaimed::class]);
    [$user, $token] = phase3ProvisionedClaim('merchant-56');

    expect($user->email_verified_at)->toBeNull();

    $this->post('/forgot-password', ['email' => 'sara@sara-store.sa'])->assertSessionHasNoErrors();

    $reset = DB::table('password_reset_tokens')->where('email', 'sara@sara-store.sa')->first();
    expect($reset)->not->toBeNull();

    // The broker verifies the raw token against the hashed row; go
    // through the real broker to mint a usable token instead.
    $rawToken = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $rawToken,
        'email' => 'sara@sara-store.sa',
        'password' => 'reset-claim-password-123',
        'password_confirmation' => 'reset-claim-password-123',
    ])->assertSessionHasNoErrors();

    $user = $user->refresh();
    expect(Hash::check('reset-claim-password-123', $user->password))->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
    expect(WorkspaceClaim::query()->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->count())->toBe(0);

    Event::assertDispatched(WorkspaceClaimed::class, fn ($event) => $event->via === 'password_reset');

    // The old claim links are dead: claimed users go to login.
    $this->get("/claim/{$token}")->assertRedirect(route('login'));
});

test('the raw claim token is stored encrypted and never serialized', function () {
    Mail::fake();
    phase3FakeSalla('merchant-link-4');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-link-4'));

    $claim = WorkspaceClaim::query()->latest('created_at')->firstOrFail();
    $raw = DB::table('workspace_claims')->where('id', $claim->id)->value('token_encrypted');

    expect($raw)->not->toBeNull()
        ->and(WorkspaceClaim::hashToken($claim->token_encrypted))->toBe($claim->token_hash)
        ->and($raw)->not->toBe($claim->token_encrypted)
        ->and($claim->toArray())->not->toHaveKeys(['token_encrypted', 'token_hash']);
});
