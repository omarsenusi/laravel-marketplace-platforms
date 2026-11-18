<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use InvalidArgumentException;

/**
 * Single-use coupon payload for POST /coupons.
 *
 * Fixed coupons carry the discount in $amountMinor; percentage coupons in
 * $percent (1-100). Targeting one customer means usage limits of 1 plus
 * that customer in $customerIds.
 *
 * @param  list<int|string>  $customerIds
 */
final readonly class CouponInput
{
    public function __construct(
        public string $code,
        public CouponType $type,
        public array $customerIds,
        public ?int $amountMinor = null,
        public ?int $percent = null,
        public ?string $startDate = null,
        public ?string $expiryDate = null,
        public int $usageLimit = 1,
        public int $usageLimitPerUser = 1,
        public ?bool $freeShipping = null,
        public ?bool $excludeSaleProducts = null,
        public ?string $status = null,
    ) {
        if ($this->type === CouponType::Fixed && $this->amountMinor === null) {
            throw new InvalidArgumentException('Fixed coupons require amountMinor.');
        }

        if ($this->type === CouponType::Percentage
            && ($this->percent === null || $this->percent < 1 || $this->percent > 100)) {
            throw new InvalidArgumentException('Percentage coupons require a percent between 1 and 100.');
        }

        if ($this->customerIds === []) {
            throw new InvalidArgumentException('Single-use coupons require at least one customer id.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'type' => $this->type->value,
            'amount' => $this->type === CouponType::Fixed && $this->amountMinor !== null
                ? (float) SallaMoney::toDecimal($this->amountMinor)
                : $this->percent,
            'status' => $this->status,
            'start_date' => $this->startDate,
            'expiry_date' => $this->expiryDate,
            'usage_limit' => $this->usageLimit,
            'usage_limit_per_user' => $this->usageLimitPerUser,
            'free_shipping' => $this->freeShipping,
            'exclude_sale_products' => $this->excludeSaleProducts,
            'include_customer_ids' => array_values($this->customerIds),
        ], static fn ($v): bool => $v !== null);
    }
}
