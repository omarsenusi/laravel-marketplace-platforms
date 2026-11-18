<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\Money;
use Omarsenusi\MarketplacePlatforms\Commerce\ProductInput;
use Omarsenusi\MarketplacePlatforms\Commerce\ProductPatch;
use Omarsenusi\MarketplacePlatforms\Commerce\ProductStatus;
use Omarsenusi\MarketplacePlatforms\Commerce\ProductVariantPatch;
use Omarsenusi\MarketplacePlatforms\Commerce\ProductWriters;

test('create sends minor units as decimals and maps the product', function () {
    $connection = commerceConnection('pw-create-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 207865447, 'name' => 'T-Shirt Blue', 'status' => 'sale'], 201),
    ]);

    $result = ProductWriters::for($connection)->create($connection, new ProductInput(
        name: 'T-Shirt Blue',
        price: new Money(9633, 'SAR'),
        productType: 'product',
        quantity: 4,
        salePrice: new Money(4300, 'SAR'),
        sku: 'TSH-1',
        categoryIds: [1032561074],
    ));

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(201)
        ->and($result->data->id)->toBe('207865447')
        ->and($result->data->status)->toBe('sale');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/products')
        ->and($request->data())->toBe([
            'name' => 'T-Shirt Blue',
            'price' => 96.33,
            'product_type' => 'product',
            'quantity' => 4,
            'categories' => [1032561074],
            'sale_price' => 43.0,
            'sku' => 'TSH-1',
        ]);
});

test('update sends a sparse patch to the product path', function () {
    $connection = commerceConnection('pw-update-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 1672908878, 'name' => 'T-Shirt', 'status' => 'sale'], 201),
    ]);

    $result = ProductWriters::for($connection)->update(
        $connection, '1672908878', new ProductPatch(name: 'T-Shirt', quantity: 7)
    );

    expect($result->ok)->toBeTrue()->and($result->data->id)->toBe('1672908878');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('PUT')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/products/1672908878')
        ->and($request->data())->toBe(['name' => 'T-Shirt', 'quantity' => 7]);
});

test('delete hits the product path and returns true', function () {
    $connection = commerceConnection('pw-delete-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 202, 'success' => true, 'data' => ['message' => 'removed', 'code' => 202]], 202),
    ]);

    $result = ProductWriters::for($connection)->delete($connection, '12');

    expect($result->ok)->toBeTrue()->and($result->data)->toBeTrue();

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('DELETE')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/products/12');
});

test('updateVariant maps price sale price sku and stock', function () {
    $connection = commerceConnection('pw-variant-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 652911549, 'sku' => 'sku-1', 'stock_quantity' => 3000], 201),
    ]);

    $result = ProductWriters::for($connection)->updateVariant(
        $connection, '652911549', new ProductVariantPatch(price: new Money(9650, 'SAR'), sku: 'sku-1')
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('652911549')
        ->and($result->data->sku)->toBe('sku-1')
        ->and($result->data->stockQuantity)->toBe(3000);

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('PUT')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/products/variants/652911549')
        ->and($request->data())->toBe(['price' => 96.5, 'sku' => 'sku-1']);
});

test('setCategories replaces via the update endpoint', function () {
    $connection = commerceConnection('pw-cats-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 5, 'name' => 'P', 'status' => 'sale'], 201),
    ]);

    $result = ProductWriters::for($connection)->setCategories($connection, '5', [11, 22]);

    expect($result->ok)->toBeTrue();

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('PUT')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/products/5')
        ->and($request->data())->toBe(['categories' => [11, 22]]);
});

test('addImageByUrl posts the image url and maps the image', function () {
    $connection = commerceConnection('pw-image-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 1908361139,
            'image' => ['original' => ['url' => 'https://cdn.example.com/a.jpg', 'width' => 0, 'height' => 0]],
        ], 201),
    ]);

    $result = ProductWriters::for($connection)->addImageByUrl($connection, '5', 'https://cdn.example.com/a.jpg', true);

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('1908361139')
        ->and($result->data->url)->toBe('https://cdn.example.com/a.jpg');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/products/5/images')
        ->and($request->data())->toBe(['original' => 'https://cdn.example.com/a.jpg', 'main' => true]);
});

test('updateStatus posts the status slug', function () {
    $connection = commerceConnection('pw-status-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 201, 'success' => true, 'data' => ['message' => 'ok', 'code' => 201]], 201),
    ]);

    $result = ProductWriters::for($connection)->updateStatus($connection, '5', ProductStatus::Sale);

    expect($result->ok)->toBeTrue()->and($result->data)->toBeTrue();

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/products/5/status')
        ->and($request->data())->toBe(['status' => 'sale']);
});

test('validation failures carry salla fields', function () {
    $connection = commerceConnection('pw-422-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaError(422, 'validation_failed', ['price' => ['The price field is required.']]),
    ]);

    $result = ProductWriters::for($connection)->create(
        $connection, new ProductInput(name: 'X', price: new Money(100, 'SAR'))
    );

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(422)
        ->and($result->errorCode)->toBe('validation_failed')
        ->and($result->fields)->toBe(['price' => ['The price field is required.']]);
});

test('a missing product maps to 404', function () {
    $connection = commerceConnection('pw-404-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 404, 'success' => false, 'error' => ['code' => 404, 'message' => 'gone']], 404),
    ]);

    $result = ProductWriters::for($connection)->delete($connection, 'nope');

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(404);
});

test('a rate limited write is a failure not a retry', function () {
    $connection = commerceConnection('pw-429-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '5']),
    ]);

    $result = ProductWriters::for($connection)->updateStatus($connection, '5', ProductStatus::Hidden);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(429)
        ->and($result->retryAfterHeader)->toBe(5);

    Http::assertSentCount(1);
});
