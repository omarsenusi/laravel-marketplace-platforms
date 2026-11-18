<?php

require_once __DIR__.'/CommerceHelpers.php';

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Commerce\ConditionMatch;
use Omarsenusi\MarketplacePlatforms\Commerce\RouteCompany;
use Omarsenusi\MarketplacePlatforms\Commerce\RouteCondition;
use Omarsenusi\MarketplacePlatforms\Commerce\RouteInput;
use Omarsenusi\MarketplacePlatforms\Commerce\RoutePatch;
use Omarsenusi\MarketplacePlatforms\Commerce\RouteType;
use Omarsenusi\MarketplacePlatforms\Commerce\ShippingSetups;
use Omarsenusi\MarketplacePlatforms\Commerce\ZoneCashOnDelivery;
use Omarsenusi\MarketplacePlatforms\Commerce\ZoneFee;
use Omarsenusi\MarketplacePlatforms\Commerce\ZoneFeeType;
use Omarsenusi\MarketplacePlatforms\Commerce\ZoneInput;

function zoneInput(): ZoneInput
{
    return new ZoneInput(
        fees: new ZoneFee(ZoneFeeType::Rate, 1100, 5.0, 200, 3.0),
        duration: '13 days',
        countryId: 1939592358,
        cityId: 1592433390,
        citiesExcluded: [257742554],
        cashOnDelivery: new ZoneCashOnDelivery(true, 1200),
    );
}

test('company crud hits the companies paths', function () {
    $connection = commerceConnection('ss-company-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::sequence([
            commerceSallaOk(['id' => 976721503, 'name' => 'Acme', 'slug' => 'acme', 'activation_type' => 'api'], 201),
            commerceSallaOk([
                ['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B'],
            ]),
            commerceSallaOk(['id' => 976721503, 'name' => 'Acme', 'slug' => 'acme'], 200),
            commerceSallaOk(['id' => 976721503, 'name' => 'Acme 2'], 200),
            Http::response(['status' => 200, 'success' => true, 'data' => ['message' => 'deleted', 'code' => 200]], 200),
        ]),
    ]);

    $setup = ShippingSetups::for($connection);

    $created = $setup->createCompany($connection, 'Acme');
    expect($created->ok)->toBeTrue()
        ->and($created->data->id)->toBe('976721503')
        ->and($created->data->name)->toBe('Acme');

    expect($setup->listCompanies($connection)->data)->toHaveCount(2);
    expect($setup->findCompany($connection, '976721503')->data->id)->toBe('976721503');
    expect($setup->updateCompany($connection, '976721503', 'Acme 2')->data->name)->toBe('Acme 2');
    expect($setup->deleteCompany($connection, '976721503')->data)->toBeTrue();

    $recorded = Http::recorded();
    expect($recorded[0][0]->method())->toBe('POST')
        ->and($recorded[0][0]->url())->toBe('https://api.salla.dev/admin/v2/shipping/companies/')
        ->and($recorded[0][0]->data())->toBe(['name' => 'Acme']);
    expect($recorded[1][0]->method())->toBe('GET');
    expect($recorded[2][0]->url())->toBe('https://api.salla.dev/admin/v2/shipping/companies/976721503');
    expect($recorded[3][0]->method())->toBe('PUT')
        ->and($recorded[3][0]->data())->toBe(['name' => 'Acme 2']);
    expect($recorded[4][0]->method())->toBe('DELETE');
});

test('createZone posts company plus shipping with decimals', function () {
    $connection = commerceConnection('ss-zone-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 1120595191, 'zone_code' => '.KW.the-villa',
            'company' => ['id' => 488260393, 'slug' => 'x'],
            'country' => ['id' => 1939592358], 'city' => ['id' => 1592433390],
            'fees' => ['amount' => '11.00', 'currency' => 'SAR', 'type' => 'rate', 'up_to_weight' => 5, 'amount_per_unit' => 2, 'per_unit' => 3],
            'cash_on_delivery' => ['status' => true, 'fees' => '12.00'],
            'duration' => '13 days',
        ], 201),
    ]);

    $result = ShippingSetups::for($connection)->createZone($connection, 488260393, zoneInput());

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('1120595191')
        ->and($result->data->companyId)->toBe('488260393')
        ->and($result->data->fees->amountMinor)->toBe(1100)
        ->and($result->data->fees->type->value)->toBe('rate')
        ->and($result->data->feesCurrency)->toBe('SAR')
        ->and($result->data->cashOnDelivery->feesMinor)->toBe(1200);

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/shipping/zones')
        ->and($request->data())->toBe([
            'company' => 488260393,
            'shipping' => [
                'country' => 1939592358,
                'city' => 1592433390,
                'cities_excluded' => [257742554],
                'cash_on_delivery' => ['status' => true, 'fees' => 12.0],
                'fees' => ['type' => 'rate', 'amount' => 11.0, 'up_to_weight' => 5.0, 'amount_per_unit' => 2.0, 'per_unit' => 3.0],
                'duration' => '13 days',
            ],
        ]);
});

test('zone update delete list and find hit their paths', function () {
    $connection = commerceConnection('ss-zone-2');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::sequence([
            commerceSallaOk(['id' => 9, 'zone_code' => 'z', 'duration' => '13 days'], 200),
            Http::response(['status' => 200, 'success' => true, 'data' => []], 200),
            commerceSallaOk([['id' => 9, 'zone_code' => 'z']]),
            commerceSallaOk(['id' => 9, 'zone_code' => 'z']),
        ]),
    ]);

    $setup = ShippingSetups::for($connection);
    expect($setup->updateZone($connection, '9', 488260393, zoneInput())->data->id)->toBe('9');
    expect($setup->deleteZone($connection, '9')->data)->toBeTrue();
    expect($setup->listZones($connection, 488260393)->data)->toHaveCount(1);
    expect($setup->findZone($connection, '9')->data->id)->toBe('9');

    $recorded = Http::recorded();
    expect($recorded[0][0]->method())->toBe('PUT')
        ->and($recorded[0][0]->url())->toBe('https://api.salla.dev/admin/v2/shipping/zones/9');
    expect($recorded[1][0]->method())->toBe('DELETE');
    expect($recorded[2][0]->method())->toBe('GET')
        ->and($recorded[2][0]->url())->toContain('company=488260393');
    expect($recorded[3][0]->method())->toBe('GET');
});

test('createRoute posts the route with typed conditions', function () {
    $connection = commerceConnection('ss-route-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => commerceSallaOk([
            'id' => 2087831307, 'name' => 'Testing Route 1', 'type' => 'branded',
            'status' => true, 'priority' => 1, 'strategy' => 'default',
        ], 201),
    ]);

    $result = ShippingSetups::for($connection)->createRoute($connection, new RouteInput(
        name: 'Testing Route 1',
        type: RouteType::Branded,
        priority: 1,
        companies: [new RouteCompany(989286562, 1, 80)],
        conditionMatch: ConditionMatch::All,
        conditions: [
            RouteCondition::between('cart_total', 200, 500),
            RouteCondition::scalar('cart_weight', '==', 3),
            RouteCondition::idList('categories', 'in', [1975114919]),
        ],
        status: 1,
    ));

    expect($result->ok)->toBeTrue()
        ->and($result->data->id)->toBe('2087831307')
        ->and($result->data->strategy)->toBe('default');

    $request = Http::recorded()->first()[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.salla.dev/admin/v2/shipping/routes')
        ->and($request->data())->toBe([
            'name' => 'Testing Route 1',
            'type' => 'branded',
            'priority' => 1,
            'status' => 1,
            'companies' => [['id' => 989286562, 'priority' => 1, 'capacity' => 80]],
            'condition_match' => 'all',
            'conditions' => [
                ['type' => 'cart_total', 'operator' => 'between', 'value' => ['min' => 200, 'max' => 500]],
                ['type' => 'cart_weight', 'operator' => '==', 'value' => 3],
                ['type' => 'categories', 'operator' => 'in', 'value' => [1975114919]],
            ],
        ]);
});

test('route update uses patch and routes list filters by name', function () {
    $connection = commerceConnection('ss-route-2');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::sequence([
            commerceSallaOk(['id' => 5, 'name' => 'New Route v1', 'status' => false]),
            commerceSallaOk([['id' => 5, 'name' => 'New Route v1']]),
            commerceSallaOk(['id' => 5, 'name' => 'New Route v1']),
            Http::response(['status' => 200, 'success' => true, 'data' => []], 200),
        ]),
    ]);

    $setup = ShippingSetups::for($connection);
    $updated = $setup->updateRoute($connection, '5', new RoutePatch(name: 'New Route v1', status: false));
    expect($updated->ok)->toBeTrue()->and($updated->data->name)->toBe('New Route v1');

    expect($setup->listRoutes($connection, 'New Route')->data)->toHaveCount(1);
    expect($setup->findRoute($connection, '5')->data->id)->toBe('5');
    expect($setup->deleteRoute($connection, '5')->data)->toBeTrue();

    $recorded = Http::recorded();
    expect($recorded[0][0]->method())->toBe('PATCH')
        ->and($recorded[0][0]->url())->toBe('https://api.salla.dev/admin/v2/shipping/routes/5')
        ->and($recorded[0][0]->data())->toBe(['name' => 'New Route v1', 'status' => false]);
    expect($recorded[1][0]->url())->toContain('name=New+Route');
});

test('a 403 on routes surfaces as forbidden', function () {
    $connection = commerceConnection('ss-403-1');
    commerceRefake([
        'accounts.salla.sa/*' => Http::response(commerceTokens(), 200),
        'api.salla.dev/*' => Http::response(['status' => 403, 'success' => false, 'error' => ['code' => 'Forbidden']], 403),
    ]);

    $result = ShippingSetups::for($connection)->createRoute($connection, new RouteInput(
        name: 'R', type: RouteType::Normal, priority: 1,
        companies: [new RouteCompany(1)], conditionMatch: ConditionMatch::Any, conditions: [],
    ));

    expect($result->ok)->toBeFalse()->and($result->forbidden)->toBeTrue();
});
