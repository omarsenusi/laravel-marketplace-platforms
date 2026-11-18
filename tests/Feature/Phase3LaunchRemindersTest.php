<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Mail;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Mail\ClaimWorkspaceMail;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\User;

test('launch for the logged-in owner goes to the dashboard', function () {
    Mail::fake();
    phase3FakeSalla('merchant-60');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-60'));

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();

    $response = $this->actingAs($user)->get("/platforms/{$platform->slug}/launch?store_id=merchant-60");

    $response->assertRedirect(route('dashboard'));
});

test('anonymous launch on an unclaimed store resends the claim email and shows the masked page', function () {
    Mail::fake();
    phase3FakeSalla('merchant-61');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-61'));

    $response = $this->get("/platforms/{$platform->slug}/launch?store_id=merchant-61");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('platforms/launch')
        ->where('maskedEmail', 's***@sara-store.sa'));

    // The token URL itself never leaks into the response.
    $claims = WorkspaceClaim::query()->get();
    expect($claims)->not->toBeEmpty();
    expect($response->getContent())->not->toContain('/claim/');

    // Resend is rate-limited to 1 per 5 minutes per connection.
    $again = $this->get("/platforms/{$platform->slug}/launch?store_id=merchant-61");
    $again->assertOk();
    $again->assertInertia(fn ($page) => $page
        ->component('platforms/launch')
        ->where('sent', false));
});

test('launch for a claimed store redirects to login', function () {
    Mail::fake();
    phase3FakeSalla('merchant-62');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-62'));

    User::query()->where('email', 'sara@sara-store.sa')->firstOrFail()
        ->forceFill(['password' => 'claimed-password-123'])->save();

    $this->get("/platforms/{$platform->slug}/launch?store_id=merchant-62")
        ->assertRedirect(route('login'));
});

test('launch accepts merchant param and 404s unknown stores', function () {
    Mail::fake();
    phase3FakeSalla('merchant-63');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-63'));

    // Salla-style ?merchant=… param.
    $response = $this->get("/platforms/{$platform->slug}/launch?merchant=merchant-63");
    $response->assertOk();

    $this->get("/platforms/{$platform->slug}/launch?store_id=nope")->assertNotFound();
    $this->get("/platforms/{$platform->slug}/launch")->assertNotFound();
});

test('reminders send at day 1, 3 and 7 only, max 3', function () {
    Mail::fake();
    phase3FakeSalla('merchant-64');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-64'));

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();
    $connection = phase3ConnectionFor($platform, 'merchant-64');
    $claims = app(ClaimService::class);

    // [daysSinceFirst, latestCount] => sends?
    $cases = [
        [0, 0, 0], // day 0: nothing yet
        [1, 0, 1], // day 1: first reminder
        [2, 1, 0], // day 2: already reminded
        [3, 1, 1], // day 3: second reminder
        [6, 2, 0], // day 6: already reminded
        [7, 2, 1], // day 7: third reminder
        [30, 3, 0], // max 3 reached: never again
    ];

    foreach ($cases as $case) {
        [$daysAgo, $latestCount, $expectedMails] = $case;
        Mail::fake();
        WorkspaceClaim::query()->where('user_id', $user->id)->delete();

        $first = $claims->issueClaim($user, $connection, null)['claim'];
        $first->forceFill(['created_at' => now()->subDays($daysAgo), 'sent_at' => now()->subDays($daysAgo)])->save();
        $latest = $claims->issueClaim($user, $connection, null)['claim'];
        $latest->forceFill(['reminder_count' => $latestCount])->save();

        $this->artisan('platforms:claims:remind')->assertSuccessful();

        $queued = Mail::queued(ClaimWorkspaceMail::class)->count();
        expect($queued)->toBe($expectedMails, "days={$daysAgo} latestCount={$latestCount}");
    }
});

test('unclaimed user with an uninstalled connection gets no reminder', function () {
    Mail::fake();
    phase3FakeSalla('merchant-65');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-65'));
    phase3RunDelivery($platform, 'app.uninstalled', ['event' => 'app.uninstalled', 'merchant' => 'merchant-65']);

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();
    $connection = phase3ConnectionFor($platform, 'merchant-65');

    // A fresh open claim exists but the connection is uninstalled.
    // Reset the mail fake: provisioning already queued its backup mail.
    Mail::fake();
    WorkspaceClaim::query()->where('user_id', $user->id)->delete();
    $claim = app(ClaimService::class)->issueClaim($user, $connection, null)['claim'];
    $claim->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('platforms:claims:remind')->assertSuccessful();

    Mail::assertQueued(ClaimWorkspaceMail::class, 0);
});
