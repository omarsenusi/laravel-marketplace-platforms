<?php

namespace Omarsenusi\MarketplacePlatforms\Support;

/**
 * One-way customer identifier for order attribution. The raw
 * marketplace customer id is NEVER stored — only this HMAC, keyed
 * by the app key and namespaced per platform driver so a hash from
 * one marketplace cannot be confused with another's.
 */
final class CustomerHash
{
    public static function for(string $driverKey, string|int $customerId): string
    {
        $customerId = trim((string) $customerId);

        return hash_hmac('sha256', $driverKey.':'.$customerId, (string) config('app.key'));
    }
}
