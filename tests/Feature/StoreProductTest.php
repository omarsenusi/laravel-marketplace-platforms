<?php

use Omarsenusi\MarketplacePlatforms\Catalog\StoreProduct;

function productSalla(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'name' => 'منتج تجريبي '.$id,
        'description' => '<p>وصف <b>المنتج</b> رقم '.$id.'</p>',
        'price' => ['amount' => 150.0, 'currency' => 'SAR'],
        'sale_price' => ['amount' => 120.0, 'currency' => 'SAR'],
        'url' => 'https://store.example.com/p/'.$id,
        'main_image' => 'https://store.example.com/img/'.$id.'.jpg',
        'quantity' => 5,
        'unlimited_quantity' => false,
        'is_available' => true,
        'status' => 'sale',
        'sku' => 'SKU-'.$id,
        'categories' => [['id' => 1, 'name' => 'ملابس']],
        'brand' => ['name' => 'علامة'],
    ], $overrides);
}

function productZid(string $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'name' => ['ar' => 'منتج زد '.$id, 'en' => 'Zid product '.$id],
        'description' => ['ar' => '<p>وصف المنتج '.$id.'</p>', 'en' => 'Description '.$id],
        'price' => 200,
        'sale_price' => 180,
        'currency' => 'SAR',
        'slug' => 'product-'.$id,
        'html_url' => 'https://zstore.example.com/products/product-'.$id,
        'images' => [['image' => ['large' => 'https://zstore.example.com/img/'.$id.'.jpg']]],
        'quantity' => 3,
        'is_infinite' => false,
        'is_published' => true,
        'sku' => 'ZSKU-'.$id,
        'categories' => [['id' => 'c1', 'name' => ['ar' => 'إلكترونيات', 'en' => 'Electronics']]],
    ], $overrides);
}

test('salla normalization prefers sale price and strips html', function () {
    $product = StoreProduct::fromSalla(productSalla(7));

    expect($product->id)->toBe('7')
        ->and($product->name)->toBe('منتج تجريبي 7')
        ->and($product->description)->toBe('وصف المنتج رقم 7')
        ->and($product->price)->toBe(120.0)
        ->and($product->regularPrice)->toBe(150.0)
        ->and($product->currency)->toBe('SAR')
        ->and($product->url)->toBe('https://store.example.com/p/7')
        ->and($product->image)->toBe('https://store.example.com/img/7.jpg')
        ->and($product->inStock)->toBeTrue()
        ->and($product->stock)->toBe(5)
        ->and($product->categories)->toBe(['ملابس'])
        ->and($product->brand)->toBe('علامة')
        ->and($product->sku)->toBe('SKU-7')
        ->and($product->visible)->toBeTrue();

    $tool = $product->toToolArray();
    expect($tool['id'])->toBe('7')
        ->and($tool['price'])->toBe(120.0)
        ->and($tool['regular_price'])->toBe(150.0)
        ->and($tool['product_url'])->toBe('https://store.example.com/p/7');
});

test('salla sale price ignored when higher than regular, hidden is invisible, unlimited has null stock', function () {
    $noSale = StoreProduct::fromSalla(productSalla(1, [
        'price' => ['amount' => 100, 'currency' => 'SAR'],
        'sale_price' => ['amount' => 130, 'currency' => 'SAR'],
    ]));
    expect($noSale->price)->toBe(100.0);

    $hidden = StoreProduct::fromSalla(productSalla(2, ['status' => 'hidden']));
    expect($hidden->visible)->toBeFalse();

    $deleted = StoreProduct::fromSalla(productSalla(3, ['status' => 'deleted']));
    expect($deleted->visible)->toBeFalse();

    $unlimited = StoreProduct::fromSalla(productSalla(4, ['unlimited_quantity' => true, 'quantity' => 0]));
    expect($unlimited->stock)->toBeNull()->and($unlimited->inStock)->toBeTrue();

    $out = StoreProduct::fromSalla(productSalla(5, ['status' => 'out', 'quantity' => 0, 'is_available' => false]));
    expect($out->visible)->toBeTrue()->and($out->inStock)->toBeFalse();
});

test('zid normalization prefers arabic and handles unpublished + infinite', function () {
    $product = StoreProduct::fromZid(productZid('uuid-1'));

    expect($product->id)->toBe('uuid-1')
        ->and($product->name)->toBe('منتج زد uuid-1')
        ->and($product->description)->toBe('وصف المنتج uuid-1')
        ->and($product->price)->toBe(180.0)
        ->and($product->regularPrice)->toBe(200.0)
        ->and($product->currency)->toBe('SAR')
        ->and($product->image)->toBe('https://zstore.example.com/img/uuid-1.jpg')
        ->and($product->inStock)->toBeTrue()
        ->and($product->stock)->toBe(3)
        ->and($product->categories)->toBe(['إلكترونيات'])
        ->and($product->visible)->toBeTrue();

    $unpublished = StoreProduct::fromZid(productZid('uuid-2', ['is_published' => false]));
    expect($unpublished->visible)->toBeFalse();

    $infinite = StoreProduct::fromZid(productZid('uuid-3', ['is_infinite' => true, 'quantity' => null]));
    expect($infinite->stock)->toBeNull()->and($infinite->inStock)->toBeTrue();
});

test('price band text and index text exclude stock', function () {
    $product = StoreProduct::fromSalla(productSalla(9));

    expect($product->indexText())->toContain('نطاق السعر: 100–200 SAR')
        ->and($product->indexText())->not->toContain('quantity')
        ->and($product->indexText())->not->toContain('"stock"')
        ->and($product->indexText())->not->toContain('المخزون');

    expect(StoreProduct::priceBand(10))->toBe('0–50');
    expect(StoreProduct::priceBand(75))->toBe('50–100');
    expect(StoreProduct::priceBand(150))->toBe('100–200');
    expect(StoreProduct::priceBand(300))->toBe('200–500');
    expect(StoreProduct::priceBand(700))->toBe('500–1000');
    expect(StoreProduct::priceBand(1500))->toBe('1000–2500');
    expect(StoreProduct::priceBand(5000))->toBe('2500+');

    expect($product->contentHash())->toBe(hash('sha256', $product->indexText()));
});

test('html size tables keep their cells apart in the plain text', function () {
    $p = StoreProduct::fromSalla([
        'id' => 9, 'name' => 'فستان', 'status' => 'sale',
        'price' => ['amount' => 100, 'currency' => 'SAR'],
        'description' => '<table><tr><td>المقاس</td><td>36</td><td>الطول</td><td>130</td></tr></table>',
    ]);

    expect($p->description)->toBe('المقاس 36 الطول 130');
});
