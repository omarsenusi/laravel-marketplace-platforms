<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\CategoryInput;
use Omarsenusi\MarketplacePlatforms\Commerce\CategoryPatch;
use Omarsenusi\MarketplacePlatforms\Commerce\CategoryStatus;
use Omarsenusi\MarketplacePlatforms\Commerce\CategoryWriters;

test('create posts the category body and maps the row', function () {
    $connection = commerceConnection('ct-create-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 1390018242, 'name' => 'laptops', 'status' => 'active',
            'image' => 'https://cdn.example.com/c.jpg', 'parent_id' => 0,
        ], 201),
    ]);

    $result = CategoryWriters::for($connection)->create($connection, new CategoryInput(
        name: 'laptops', status: CategoryStatus::Active, image: 'https://cdn.example.com/c.jpg',
    ));

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('1390018242')
        ->and($result->data->name)->toBe('laptops')
        ->and($result->data->status)->toBe('active')
        ->and($result->data->parentId)->toBe(0);

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/categories')
        ->and($request->data())->toBe([
            'name' => 'laptops', 'status' => 'active', 'image' => 'https://cdn.example.com/c.jpg',
        ]);
});

test('find update and delete hit the category path', function () {
    $connection = commerceConnection('ct-paths-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::sequence([
            commerceSallaOk(['id' => 9, 'name' => 'food', 'status' => 'hidden']),
            commerceSallaOk(['id' => 9, 'name' => 'food 2', 'status' => 'active']),
            Http::response(['status' => 202, 'success' => true, 'data' => ['message' => 'removed', 'code' => 202]], 202),
        ]),
    ]);

    $writer = CategoryWriters::for($connection);
    expect($writer->find($connection, '9')->data->status)->toBe('hidden');
    expect($writer->update($connection, '9', new CategoryPatch(name: 'food 2', status: CategoryStatus::Active))->data->name)->toBe('food 2');
    expect($writer->delete($connection, '9')->data)->toBeTrue();

    $recorded = Http::recorded();
    expect($recorded[0][0]->method())->toBe('GET')
        ->and($recorded[0][0]->url())->toBe('https://api.salla.dev/admin/v2/categories/9');
    expect($recorded[1][0]->method())->toBe('PUT')
        ->and($recorded[1][0]->data())->toBe(['name' => 'food 2', 'status' => 'active']);
    expect($recorded[2][0]->method())->toBe('DELETE');
});

test('list paginates with keyword and status filters', function () {
    $connection = commerceConnection('ct-list-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response([
            'status' => 200, 'success' => true,
            'data' => [['id' => 1, 'name' => 'food', 'status' => 'active']],
            'pagination' => ['currentPage' => 2, 'totalPages' => 2, 'total' => 20],
        ], 200),
    ]);

    $result = CategoryWriters::for($connection)->list($connection, 2, 'food', CategoryStatus::Active);

    expect($result->ok)->toBeTrue()
        ->and(count($result->data->categories))->toBe(1)
        ->and($result->data->nextPage)->toBeNull()
        ->and($result->data->total)->toBe(20);

    $url = Http::recorded()->first()[0]->url();
    expect($url)->toContain('page=2')->and($url)->toContain('keyword=food')->and($url)->toContain('status=active');
});

test('a missing category maps to 404 with one call', function () {
    $connection = commerceConnection('ct-404-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 404, 'success' => false, 'error' => ['code' => 404, 'message' => 'gone']], 404),
    ]);

    $result = CategoryWriters::for($connection)->find($connection, 'nope');

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(404);
    Http::assertSentCount(1);
});
