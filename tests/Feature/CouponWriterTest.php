<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\CouponInput;
use Omarsenusi\MarketplacePlatforms\Commerce\CouponType;
use Omarsenusi\MarketplacePlatforms\Commerce\CouponWriters;

test('createSingleUse targets one customer with limits of one', function () {
    $connection = commerceConnection('cp-create-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 1622617640, 'code' => 'WELCOME10', 'type' => 'fixed', 'status' => 'active',
        ], 200),
    ]);

    $result = CouponWriters::for($connection)->createSingleUse($connection, new CouponInput(
        code: 'WELCOME10', type: CouponType::Fixed, customerIds: ['123987'],
        amountMinor: 1000, expiryDate: '2026-12-12 00:00:00',
    ));

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('1622617640')
        ->and($result->data->code)->toBe('WELCOME10')
        ->and($result->data->type)->toBe('fixed');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/coupons')
        ->and($request->data())->toBe([
            'code' => 'WELCOME10', 'type' => 'fixed', 'amount' => 10.0,
            'expiry_date' => '2026-12-12 00:00:00',
            'usage_limit' => 1, 'usage_limit_per_user' => 1,
            'include_customer_ids' => ['123987'],
        ]);
});

test('percentage coupons send the percent value', function () {
    $connection = commerceConnection('cp-percent-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 2, 'code' => 'P10', 'type' => 'percentage', 'status' => 'active'], 200),
    ]);

    $result = CouponWriters::for($connection)->createSingleUse($connection, new CouponInput(
        code: 'P10', type: CouponType::Percentage, customerIds: [7], percent: 10,
    ));

    expect($result->ok)->toBeTrue();
    expect(Http::recorded()->first()[0]->data()['amount'])->toBe(10);
});

test('duplicate codes surface the 422 fields without a retry', function () {
    $connection = commerceConnection('cp-422-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaError(422, 'validation_failed', ['code' => ['The code has already been taken.']]),
    ]);

    $result = CouponWriters::for($connection)->createSingleUse($connection, new CouponInput(
        code: 'WELCOME10', type: CouponType::Fixed, customerIds: [1], amountMinor: 500,
    ));

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(422)
        ->and($result->fields)->toBe(['code' => ['The code has already been taken.']]);

    Http::assertSentCount(1);
});
