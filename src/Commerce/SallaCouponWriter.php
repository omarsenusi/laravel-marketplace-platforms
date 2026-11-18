<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla coupon writes (base https://api.salla.dev/admin/v2).
 */
final class SallaCouponWriter implements CouponWriter
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function createSingleUse(PlatformConnection $connection, CouponInput $input): CommerceResult
    {
        $result = $this->gateway->post($connection, '/coupons', $input->toSallaArray());

        return CommerceResult::fromApiResult($result, function ($data): Coupon {
            $row = is_array($data) ? $data : [];

            return new Coupon(
                id: (string) ($row['id'] ?? ''),
                code: isset($row['code']) && is_string($row['code']) ? $row['code'] : null,
                type: isset($row['type']) && is_string($row['type']) ? $row['type'] : null,
                status: isset($row['status']) && is_string($row['status']) ? $row['status'] : null,
                raw: $row,
            );
        });
    }
}
