<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\OrderWriters;

test('updateStatus posts slug and flags to the status path', function () {
    $connection = commerceConnection('ow-status-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 201, 'success' => true, 'data' => ['message' => 'updated', 'code' => 201]], 201),
    ]);

    $result = OrderWriters::for($connection)->updateStatus(
        $connection, '76587644', 'restoring', null, 'On hold', true, false
    );

    expect($result->ok)->toBeTrue()->and($result->data->message)->toBe('updated');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/orders/76587644/status')
        ->and($request->data())->toBe([
            'slug' => 'restoring', 'note' => 'On hold', 'restore_items' => true, 'send_status_sms' => false,
        ]);
});

test('updateStatus accepts a numeric status id instead', function () {
    $connection = commerceConnection('ow-status-id-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 201, 'success' => true, 'data' => ['message' => 'ok', 'code' => 201]], 201),
    ]);

    $result = OrderWriters::for($connection)->updateStatus($connection, '1', null, 1360188634);

    expect($result->ok)->toBeTrue();
    expect(Http::recorded()->first()[0]->data())->toBe(['status_id' => 1360188634]);
});

test('listStatuses maps the status rows', function () {
    $connection = commerceConnection('ow-list-status-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            ['id' => 1, 'name' => 'Completed', 'slug' => 'completed', 'type' => 'original', 'is_active' => true],
            ['id' => 2, 'name' => 'Custom', 'slug' => 'completed', 'type' => 'custom', 'is_active' => false],
        ]),
    ]);

    $result = OrderWriters::for($connection)->listStatuses($connection);

    expect($result->ok)->toBeTrue()
        ->and(count($result->data))->toBe(2)
        ->and($result->data[0]->slug)->toBe('completed')
        ->and($result->data[1]->type)->toBe('custom');

    expect(Http::recorded()->first()[0]->url())->toBe('https://api.salla.dev/admin/v2/orders/statuses');
});

test('createCustomStatus posts the custom status body', function () {
    $connection = commerceConnection('ow-custom-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 120960473, 'name' => 'Waiting', 'type' => 'custom', 'slug' => 'payment_pending',
            'message' => 'Pay now', 'icon' => 'sicon-cup-hot', 'sort' => 3, 'is_active' => true,
        ], 200),
    ]);

    $result = OrderWriters::for($connection)->createCustomStatus($connection, 863076598, 'Waiting', 'Pay now', 'sicon-cup-hot', 3, true);

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('120960473')
        ->and($result->data->type)->toBe('custom');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/orders/statuses')
        ->and($request->data())->toBe([
            'parent_id' => 863076598, 'name' => 'Waiting', 'message' => 'Pay now',
            'icon' => 'sicon-cup-hot', 'sort' => 3, 'is_active' => true,
        ]);
});

test('find reads the light order with total as money', function () {
    $connection = commerceConnection('ow-find-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 560695738, 'reference_id' => 48927,
            'status' => ['name' => 'Delivered', 'slug' => 'delivered'],
            'total' => ['amount' => 150.0, 'currency' => 'SAR'],
        ]),
    ]);

    $result = OrderWriters::for($connection)->find($connection, '560695738');

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('560695738')
        ->and($result->data->referenceId)->toBe(48927)
        ->and($result->data->statusSlug)->toBe('delivered')
        ->and($result->data->total->amountMinor)->toBe(15000)
        ->and($result->data->total->currency)->toBe('SAR');

    expect(Http::recorded()->first()[0]->url())->toContain('/orders/560695738?format=light');
});

test('listSince polls with creation dates and updated sorting', function () {
    $connection = commerceConnection('ow-since-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response([
            'status' => 200, 'success' => true,
            'data' => [['id' => 1, 'reference_id' => 10, 'status' => ['slug' => 'completed'], 'total' => ['amount' => 20, 'currency' => 'SAR']]],
            'pagination' => ['currentPage' => 1, 'totalPages' => 5, 'total' => 50],
        ], 200),
    ]);

    $result = OrderWriters::for($connection)->listSince(
        $connection, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'), 2
    );

    expect($result->ok)->toBeTrue()
        ->and(count($result->data->orders))->toBe(1)
        ->and($result->data->nextPage)->toBe(2)
        ->and($result->data->total)->toBe(50);

    $url = Http::recorded()->first()[0]->url();
    expect($url)->toContain('from_date=2026-01-01')
        ->and($url)->toContain('to_date=2026-02-01')
        ->and($url)->toContain('sort_by=updated_at-desc')
        ->and($url)->toContain('page=2');
});

test('listItems maps order lines with money', function () {
    $connection = commerceConnection('ow-items-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            ['id' => 365435777, 'name' => 'SSD', 'sku' => 'sam-ssd-256g', 'quantity' => 1,
                'amounts' => ['total' => ['amount' => 150, 'currency' => 'SAR']]],
        ]),
    ]);

    $result = OrderWriters::for($connection)->listItems($connection, '90828');

    expect($result->ok)->toBeTrue()
        ->and(count($result->data))->toBe(1)
        ->and($result->data[0]->sku)->toBe('sam-ssd-256g')
        ->and($result->data[0]->price->amountMinor)->toBe(15000);

    expect(Http::recorded()->first()[0]->url())->toContain('/orders/items?order_id=90828');
});

test('a forbidden status update surfaces 403 distinctly', function () {
    $connection = commerceConnection('ow-403-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 403, 'success' => false, 'error' => ['code' => 'Forbidden']], 403),
    ]);

    $result = OrderWriters::for($connection)->updateStatus($connection, '1', 'completed');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(403)
        ->and($result->forbidden)->toBeTrue();
});
