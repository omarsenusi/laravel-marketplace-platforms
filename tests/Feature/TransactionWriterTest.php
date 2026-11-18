<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\Money;
use Omarsenusi\MarketplacePlatforms\Commerce\TransactionAction;
use Omarsenusi\MarketplacePlatforms\Commerce\TransactionWriters;

test('update refunds partially with the decimal amount', function () {
    $connection = commerceConnection('tx-update-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response([
            'status' => 201, 'success' => true,
            'data' => ['message' => 'Invoice Partially Refunded successfully', 'code' => 201],
        ], 201),
    ]);

    $result = TransactionWriters::for($connection)->update(
        $connection, '347825634045', TransactionAction::Refund, new Money(9000, 'SAR')
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data->message)->toBe('Invoice Partially Refunded successfully');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('PUT')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/transactions/347825634045')
        ->and($request->data())->toBe(['action' => 'refund', 'amount' => 90.0, 'currency' => 'SAR']);
});

test('update supports void without an amount', function () {
    $connection = commerceConnection('tx-void-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 201, 'success' => true, 'data' => ['message' => 'voided', 'code' => 201]], 201),
    ]);

    $result = TransactionWriters::for($connection)->update($connection, '1', TransactionAction::Void);

    expect($result->ok)->toBeTrue();
    expect(Http::recorded()->first()[0]->data())->toBe(['action' => 'void']);
});

test('listForOrder maps transactions with money', function () {
    $connection = commerceConnection('tx-list-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([[
            'id' => 347825634045,
            'references' => ['order_id' => 65239480, 'reference_id' => 205904717],
            'total' => ['amount' => 50, 'currency' => 'SAR'],
            'payment_method' => ['payment_provider' => 'mada'],
        ]]),
    ]);

    $result = TransactionWriters::for($connection)->listForOrder($connection, 65239480);

    expect($result->ok)->toBeTrue()
        ->and(count($result->data))->toBe(1)
        ->and($result->data[0]->id)->toBe('347825634045')
        ->and($result->data[0]->orderId)->toBe(65239480)
        ->and($result->data[0]->referenceId)->toBe(205904717)
        ->and($result->data[0]->total->amountMinor)->toBe(5000)
        ->and($result->data[0]->paymentProvider)->toBe('mada');

    expect(Http::recorded()->first()[0]->url())->toContain('/transactions?order_id=65239480');
});

test('a failed refund carries the 422 fields', function () {
    $connection = commerceConnection('tx-422-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaError(422, '422', ['amount' => ['No funds available.']]),
    ]);

    $result = TransactionWriters::for($connection)->update(
        $connection, '1', TransactionAction::Refund, new Money(999999, 'SAR')
    );

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(422)
        ->and($result->fields)->toBe(['amount' => ['No funds available.']]);

    Http::assertSentCount(1);
});
