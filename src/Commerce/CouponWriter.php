<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Single-use customer coupons (Salla Admin API).
 *
 * POST /coupons with usage_limit=1, usage_limit_per_user=1 and
 * include_customer_ids=[…] (verified against
 * https://docs.salla.dev/coupon/create.md). Results never throw for
 * HTTP failures — see CommerceResult.
 */
interface CouponWriter
{
    /**
     * @return CommerceResult<Coupon>
     */
    public function createSingleUse(PlatformConnection $connection, CouponInput $input): CommerceResult;
}
