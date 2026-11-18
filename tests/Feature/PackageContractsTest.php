<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Mail;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Contracts\ProvisionsAccounts;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Services\Provisioner;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\User;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Workspace;

test('user and workspace models resolve through config', function () {
    config([
        'platforms.models.user' => User::class,
        'platforms.models.workspace' => Workspace::class,
    ]);

    Mail::fake();
    phase3FakeSalla('merchant-cfg-1');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-cfg-1'));

    $connection = phase3ActiveConnection($platform, 'merchant-cfg-1');

    expect($connection->workspace)->toBeInstanceOf(Workspace::class);

    $claim = WorkspaceClaim::query()->where('connection_id', $connection->id)->firstOrFail();
    expect($claim->user)->toBeInstanceOf(User::class);
    expect($claim->workspace)->toBeInstanceOf(Workspace::class);
});

test('provisioning without a ProvisionsAccounts binding throws a clear exception', function () {
    unset($this->app[ProvisionsAccounts::class]);

    Mail::fake();
    phase3FakeSalla('merchant-cfg-2');
    $platform = phase3SallaPlatform();

    expect(fn () => app(Provisioner::class)->provision(
        $platform,
        new StoreProfile(externalStoreId: 'merchant-cfg-2', ownerEmail: 'owner@example.test'),
        null,
        'test',
    ))->toThrow(
        LogicException::class,
        'No ['.ProvisionsAccounts::class.'] binding found.',
    );
});

test('claim relations follow a custom configured user model', function () {
    config(['platforms.models.user' => User::class]);

    Mail::fake();
    phase3FakeSalla('merchant-cfg-3');
    $platform = phase3SallaPlatform();
    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload('merchant-cfg-3'));

    $user = User::query()->where('email', 'sara@sara-store.sa')->firstOrFail();

    expect(WorkspaceClaim::isClaimed($user))->toBeFalse();

    $user->forceFill(['password' => 'secret-123'])->save();

    expect(WorkspaceClaim::isClaimed($user->refresh()))->toBeTrue();
});
