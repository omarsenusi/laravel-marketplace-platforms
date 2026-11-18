<?php

require_once __DIR__.'/CommerceHelpers.php';

use Omarsenusi\MarketplacePlatforms\Commerce\CategoryWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\CouponWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\InventoryWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\OrderWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\ProductWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\SettlementGateways;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\ShippingSetups;
use Omarsenusi\MarketplacePlatforms\Commerce\TransactionWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\UnsupportedOperation;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Workspace;

test('zid connections resolve to UnsupportedOperation for every capability', function () {
    $platform = phase3ZidPlatform();
    $connection = $platform->connections()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'external_store_id' => 'zid-cap-1',
        'status' => ConnectionStatus::Active,
        'tokens' => ['manager_token' => 'm', 'authorization_token' => 'a', 'refresh_token' => 'r'],
    ]);

    $resolvers = [
        'ProductWriter' => fn () => ProductWriters::for($connection),
        'InventoryWriter' => fn () => InventoryWriters::for($connection),
        'ShipmentWriter' => fn () => ShipmentWriters::for($connection),
        'SettlementGateway' => fn () => SettlementGateways::for($connection),
        'OrderWriter' => fn () => OrderWriters::for($connection),
        'ShippingSetup' => fn () => ShippingSetups::for($connection),
        'CategoryWriter' => fn () => CategoryWriters::for($connection),
        'TransactionWriter' => fn () => TransactionWriters::for($connection),
        'CouponWriter' => fn () => CouponWriters::for($connection),
    ];

    foreach ($resolvers as $capability => $resolve) {
        try {
            $resolve();
            expect(false)->toBeTrue("expected UnsupportedOperation for {$capability}");
        } catch (UnsupportedOperation $e) {
            expect($e->getMessage())->toBe("No {$capability} implementation for driver [zid].");
        }
    }

    expect(true)->toBeTrue();
});
