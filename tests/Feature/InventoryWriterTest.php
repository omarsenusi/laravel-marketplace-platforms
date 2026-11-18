<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\InventoryAdjustReport;
use Omarsenusi\MarketplacePlatforms\Commerce\InventoryWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\StockAdjustment;
use Omarsenusi\MarketplacePlatforms\Commerce\StockIdentifierType;
use Omarsenusi\MarketplacePlatforms\Commerce\StockMode;

test('mode is required on every adjustment', function () {
    expect(static fn (): StockAdjustment => new StockAdjustment(StockIdentifierType::Id, '613398835', 5))
        ->toThrow(TypeError::class);
});

test('adjust posts salla spellings and aggregates chunks', function () {
    $connection = commerceConnection('inv-adjust-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response([
            'status' => 201, 'success' => true,
            'data' => ['message' => 'The details has been queued to update', 'code' => 201],
        ], 201),
    ]);

    $result = InventoryWriters::for($connection)->adjust($connection, [
        new StockAdjustment(StockIdentifierType::Id, '613398835', 5, StockMode::Decrement, 349994915, 566146469),
        new StockAdjustment(StockIdentifierType::VariantId, '3498732894', 14, StockMode::Increment),
        new StockAdjustment(StockIdentifierType::Sku, '250-SS', 3, StockMode::Increment),
    ], 2);

    expect($result->ok)->toBeTrue()
        ->and($result->data->accepted)->toBeTrue()
        ->and($result->data->chunkCount)->toBe(2)
        ->and($result->data->adjustmentCount)->toBe(3)
        ->and($result->data->message)->toBe('The details has been queued to update');

    $recorded = Http::recorded();
    expect($recorded->count())->toBe(2);

    $first = $recorded[0][0];
    expect($first->method())->toBe('POST')
        ->and($first->url())->toBe('https://api.salla.dev/admin/v2/products/quantities/bulk')
        ->and($first->data())->toBe(['products' => [
            ['identifer_type' => 'id', 'identifer' => '613398835', 'quantity' => 5, 'mode' => 'decrement', 'branch' => 349994915, 'reason_id' => 566146469],
            ['identifer_type' => 'variant_id', 'identifer' => '3498732894', 'quantity' => 14, 'mode' => 'increment'],
        ]]);

    // The third adjustment (sku, default overwrite) went in chunk two.
    expect($recorded[1][0]->data())->toBe(['products' => [
        ['identifer_type' => 'sku', 'identifer' => '250-SS', 'quantity' => 3, 'mode' => 'increment'],
    ]]);
});

test('adjust failure reports the failing chunk without more calls', function () {
    $connection = commerceConnection('inv-adjust-422-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaError(422, 'validation_failed', ['products.0.quantity' => ['required.']]),
    ]);

    $result = InventoryWriters::for($connection)->adjust($connection, [
        new StockAdjustment(StockIdentifierType::Id, '1', 0, StockMode::Decrement),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(422)
        ->and($result->fields)->toBe(['products.0.quantity' => ['required.']]);

    Http::assertSentCount(1);
});

test('a failed middle chunk reports applied work and pending retries only', function () {
    $connection = commerceConnection('inv-adjust-partial-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::sequence([
            Http::response([
                'status' => 201, 'success' => true,
                'data' => ['message' => 'The details has been queued to update', 'code' => 201],
            ], 201),
            commerceSallaError(422, 'validation_failed', ['products.1.quantity' => ['required.']]),
        ]),
    ]);

    $result = InventoryWriters::for($connection)->adjust($connection, [
        new StockAdjustment(StockIdentifierType::Sku, 'A-1', 1, StockMode::Decrement),
        new StockAdjustment(StockIdentifierType::Sku, 'A-2', 2, StockMode::Decrement),
        new StockAdjustment(StockIdentifierType::Sku, 'B-1', 3, StockMode::Increment),
        new StockAdjustment(StockIdentifierType::Sku, 'B-2', 4, StockMode::Increment),
        new StockAdjustment(StockIdentifierType::Sku, 'C-1', 5, StockMode::Decrement),
    ], 2);

    // Failed result, but the report still describes what applied so the
    // caller retries only the pending adjustments.
    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(422)
        ->and($result->error)->toBe('http_422')
        ->and($result->errorCode)->toBe('validation_failed')
        ->and($result->fields)->toBe(['products.1.quantity' => ['required.']]);

    $report = $result->data;
    expect($report)->toBeInstanceOf(InventoryAdjustReport::class)
        ->and($report->accepted)->toBeFalse()
        ->and($report->chunkCount)->toBe(1)
        ->and($report->adjustmentCount)->toBe(2)
        ->and(array_map(static fn (StockAdjustment $a): string => $a->identifier, $report->pending))
        ->toBe(['B-1', 'B-2', 'C-1']);

    // The third chunk never went out.
    Http::assertSentCount(2);
});

test('variantQuantity scans the quantities list and maps the row', function () {
    $connection = commerceConnection('inv-qty-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            ['id' => 1730800454, 'name' => 'SSD', 'sku_id' => 442024243, 'sku' => 'sam-ssd-128g', 'quantity' => 127, 'sold_quantity' => 340, 'unlimited_quantity' => false],
            ['id' => 1730800454, 'name' => 'SSD', 'sku_id' => 1948639292, 'sku' => 'sam-ssd-256g', 'quantity' => 254, 'sold_quantity' => 340, 'unlimited_quantity' => false],
        ]),
    ]);

    $result = InventoryWriters::for($connection)->variantQuantity($connection, '1948639292');

    expect($result->ok)->toBeTrue()
        ->and($result->data->productId)->toBe('1730800454')
        ->and($result->data->variantId)->toBe('1948639292')
        ->and($result->data->sku)->toBe('sam-ssd-256g')
        ->and($result->data->quantity)->toBe(254)
        ->and($result->data->unlimited)->toBeFalse()
        ->and($result->data->soldQuantity)->toBe(340);

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('GET')
        ->and($request->url())->toStartWith('https://api.salla.dev/admin/v2/products/quantities');
});

test('variantQuantity misses to 404 and forwards the branch filter', function () {
    $connection = commerceConnection('inv-qty-404-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([], 200),
    ]);

    $result = InventoryWriters::for($connection)->variantQuantity($connection, 'nope', 43957834);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(404);

    expect(Http::recorded()->first()[0]->url())->toContain('branch=43957834');
});
