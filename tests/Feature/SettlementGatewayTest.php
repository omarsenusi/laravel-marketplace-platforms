<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\Money;
use Omarsenusi\MarketplacePlatforms\Commerce\SettlementGateways;
use Omarsenusi\MarketplacePlatforms\Commerce\SettlementStatus;

test('create holds funds with the decimal amount', function () {
    $connection = commerceConnection('st-create-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 897542106, 'reference_id' => 653443, 'amount' => 153.35, 'status' => 'pending'], 201),
    ]);

    $result = SettlementGateways::for($connection)->create($connection, 653443, new Money(15335, 'SAR'));

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('897542106')
        ->and($result->data->referenceId)->toBe(653443)
        ->and($result->data->amountMinor)->toBe(15335)
        ->and($result->data->currency)->toBe('SAR')
        ->and($result->data->status)->toBe(SettlementStatus::Pending);

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/settlements/instant')
        ->and($request->data())->toBe(['reference_id' => 653443, 'amount' => 153.35]);
});

test('update approves the hold as ready', function () {
    $connection = commerceConnection('st-update-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 1, 'reference_id' => 5, 'amount' => 120.0, 'status' => 'ready'], 200),
    ]);

    $result = SettlementGateways::for($connection)->update($connection, '1', new Money(12000, 'SAR'), SettlementStatus::Ready);

    expect($result->ok)->toBeTrue()->and($result->data->status)->toBe(SettlementStatus::Ready);

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('PUT')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/settlements/instant/1')
        ->and($request->data())->toBe(['amount' => 120.0, 'status' => 'ready']);
});

test('find lists and deletes hit their paths', function () {
    $connection = commerceConnection('st-paths-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::sequence([
            commerceSallaOk(['id' => 1, 'reference_id' => 5, 'amount' => 10, 'status' => 'settled'], 200),
            Http::response([
                'status' => 200, 'success' => true,
                'data' => [['id' => 1, 'reference_id' => 5, 'amount' => 10, 'status' => 'settled']],
            ], 200),
            Http::response(['status' => 200, 'success' => true, 'data' => ['message' => 'deleted', 'code' => 200]], 200),
        ]),
    ]);

    $gateway = SettlementGateways::for($connection);
    expect($gateway->find($connection, '1')->data->status->value)->toBe('settled');

    $list = $gateway->list($connection, 5, 2);
    expect($list->ok)->toBeTrue()->and(count($list->data->settlements))->toBe(1);

    expect($gateway->delete($connection, '1')->data)->toBeTrue();

    $recorded = Http::recorded();
    expect($recorded[0][0]->method())->toBe('GET')
        ->and($recorded[0][0]->url())->toBe('https://api.salla.dev/admin/v2/settlements/instant/1')
        ->and($recorded[1][0]->url())->toContain('reference_id=5')
        ->and($recorded[1][0]->url())->toContain('page=2')
        ->and($recorded[2][0]->method())->toBe('DELETE')
        ->and($recorded[2][0]->url())->toBe('https://api.salla.dev/admin/v2/settlements/instant/1');
});

test('a 403 surfaces as a distinct forbidden failure', function () {
    $connection = commerceConnection('st-403-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(
            ['status' => 403, 'success' => false, 'error' => ['code' => 'Forbidden', 'message' => 'dropshipping only']],
            403,
        ),
    ]);

    $result = SettlementGateways::for($connection)->find($connection, '1');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(403)
        ->and($result->forbidden)->toBeTrue()
        ->and($result->error)->toBe('settlements_forbidden');

    Http::assertSentCount(1);
});

test('settled and canceled are rejected before any http call', function () {
    $connection = commerceConnection('st-terminal-1');
    commerceRefake(['api.salla.dev/*' => commerceSallaOk(['id' => 1], 200)]);

    foreach ([SettlementStatus::Settled, SettlementStatus::Canceled] as $status) {
        try {
            SettlementGateways::for($connection)->update($connection, '1', null, $status);
            expect(false)->toBeTrue("expected InvalidArgumentException for {$status->value}");
        } catch (InvalidArgumentException) {
            expect(true)->toBeTrue();
        }
    }

    Http::assertNothingSent();
});
