<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\Money;
use Omarsenusi\MarketplacePlatforms\Commerce\NationalAddress;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipFromBranch;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentInput;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentPackage;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentPaymentMethod;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentType;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentUpdate;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentWriters;

function shipmentAddress(): NationalAddress
{
    return new NationalAddress(
        name: 'Sara', email: 's@s.sa', phone: '0555555555',
        country: 'SA', city: 'Riyadh', addressLine: 'Tahlia Street',
        streetNumber: '120', block: 'AB', shortAddress: 'RHSA3184',
        buildingNumber: '5436', additionalNumber: '8976', postalCode: '12262',
    );
}

function shipmentInput(): ShipmentInput
{
    return new ShipmentInput(
        orderId: 560695738,
        courierId: 814202285,
        type: ShipmentType::Shipment,
        paymentMethod: ShipmentPaymentMethod::Cod,
        packages: [new ShipmentPackage(
            name: 'T-Shirt', quantity: 2, itemId: 2077288690, sku: 'TSH-1',
            price: new Money(5000, 'SAR'), weightValue: 1.0, weightUnit: 'kg',
        )],
        shipFrom: new ShipFromBranch(1723506348),
        shipTo: shipmentAddress(),
        cashOnDelivery: new Money(1070, 'SAR'),
        externalCompanyName: 'Salla Express',
        overwriteExistsPending: true,
        description: 'Fashion Apparel',
        remarks: 'After 5 PM',
    );
}

test('create posts the full shipment body with minor units as decimals', function () {
    $connection = commerceConnection('sh-create-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 362985662, 'order_id' => 560695738, 'order_reference_id' => 48927,
            'type' => 'shipment', 'status' => 'creating', 'courier_name' => 'DHL',
            'tracking_number' => '0', 'tracking_link' => null, 'shipping_number' => '0',
            'cash_on_delivery' => ['amount' => 10.70, 'currency' => 'SAR'],
        ], 200),
    ]);

    $result = ShipmentWriters::for($connection)->create($connection, shipmentInput());

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('362985662')
        ->and($result->data->courierName)->toBe('DHL')
        ->and($result->data->cashOnDelivery->amountMinor)->toBe(1070)
        ->and($result->data->cashOnDelivery->currency)->toBe('SAR');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/shipments');

    $body = $request->data();
    expect($body['order_id'])->toBe(560695738)
        ->and($body['courier_id'])->toBe(814202285)
        ->and($body['shipment_type'])->toBe('shipment')
        ->and($body['payment_method'])->toBe('cod')
        ->and($body['cash_on_delivery'])->toBe(['amount' => 10.7, 'currency' => 'SAR'])
        ->and($body['packages'])->toBe([[
            'item_id' => 2077288690, 'name' => 'T-Shirt', 'sku' => 'TSH-1',
            'price' => ['amount' => 50.0, 'currency' => 'SAR'],
            'quantity' => 2, 'weight' => ['value' => 1.0, 'units' => 'kg'],
        ]])
        ->and($body['ship_from'])->toBe(['type' => 'branch', 'branch_id' => 1723506348])
        ->and($body['ship_to']['short_address'])->toBe('RHSA3184')
        ->and($body['external_company_name'])->toBe('Salla Express')
        ->and($body['overwrite_exists_pending'])->toBeTrue();
});

test('update puts tracking fields on the shipment path', function () {
    $connection = commerceConnection('sh-update-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 1, 'status' => 'delivering', 'tracking_number' => '54563653'], 200),
    ]);

    $result = ShipmentWriters::for($connection)->update(
        $connection, '1', new ShipmentUpdate(status: 'delivering', trackingNumber: '54563653', trackingLink: 'https://t.example/1')
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data->status)->toBe('delivering')
        ->and($result->data->trackingNumber)->toBe('54563653');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('PUT')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/shipments/1')
        ->and($request->data())->toBe([
            'status' => 'delivering', 'tracking_number' => '54563653', 'tracking_link' => 'https://t.example/1',
        ]);
});

test('find and cancel and return hit their paths', function () {
    $connection = commerceConnection('sh-paths-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk(['id' => 7, 'status' => 'created'], 200),
    ]);

    $writer = ShipmentWriters::for($connection);
    expect($writer->find($connection, '7')->data->id)->toBe('7');
    expect($writer->cancel($connection, '7')->ok)->toBeTrue();
    expect($writer->returnShipment($connection, '7')->ok)->toBeTrue();

    $recorded = Http::recorded();
    expect($recorded[0][0]->method())->toBe('GET')
        ->and($recorded[0][0]->url())->toBe('https://api.salla.dev/admin/v2/shipments/7')
        ->and($recorded[1][0]->method())->toBe('POST')
        ->and($recorded[1][0]->url())->toBe('https://api.salla.dev/admin/v2/shipments/7/cancel')
        ->and($recorded[2][0]->method())->toBe('POST')
        ->and($recorded[2][0]->url())->toBe('https://api.salla.dev/admin/v2/shipments/7/return');
});

test('listForOrder filters by order with pagination', function () {
    $connection = commerceConnection('sh-list-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response([
            'status' => 200, 'success' => true,
            'data' => [['id' => 1, 'status' => 'created'], ['id' => 2, 'status' => 'delivering']],
            'pagination' => ['currentPage' => 1, 'totalPages' => 3, 'total' => 30],
        ], 200),
    ]);

    $result = ShipmentWriters::for($connection)->listForOrder($connection, 560695738, 1, 15);

    expect($result->ok)->toBeTrue()
        ->and(count($result->data->shipments))->toBe(2)
        ->and($result->data->nextPage)->toBe(2)
        ->and($result->data->total)->toBe(30);

    $url = Http::recorded()->first()[0]->url();
    expect($url)->toContain('order_id=560695738')->and($url)->toContain('page=1');
});

test('tracking maps history events', function () {
    $connection = commerceConnection('sh-track-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 362985662, 'status' => 'delivering', 'tracking_number' => '43534254',
            'tracking_link' => 'https://t.example/1',
            'history' => [
                ['status' => 'delivering', 'note' => 'on the way', 'create_at' => ['date' => '2023-04-06 11:57:51.000000']],
                ['status' => 'created', 'note' => null, 'create_at' => ['date' => '2023-04-06 11:49:51.000000']],
            ],
        ], 200),
    ]);

    $result = ShipmentWriters::for($connection)->tracking($connection, '362985662');

    expect($result->ok)->toBeTrue()
        ->and($result->data->shipmentId)->toBe('362985662')
        ->and($result->data->status)->toBe('delivering')
        ->and(count($result->data->history))->toBe(2)
        ->and($result->data->history[0]->note)->toBe('on the way')
        ->and($result->data->history[0]->createdAt)->toBe('2023-04-06 11:57:51.000000');

    expect(Http::recorded()->first()[0]->url())
        ->toBe('https://api.salla.dev/admin/v2/shipments/362985662/tracking');
});

test('validation failures carry salla fields and 404 maps cleanly', function () {
    $connection = commerceConnection('sh-fail-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaError(422, 'error', ['courier_id' => ['required.']]),
    ]);

    $result = ShipmentWriters::for($connection)->create($connection, shipmentInput());

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(422)
        ->and($result->fields)->toBe(['courier_id' => ['required.']]);
});
