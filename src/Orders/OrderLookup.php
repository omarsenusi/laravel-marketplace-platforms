<?php

namespace Omarsenusi\MarketplacePlatforms\Orders;

use Illuminate\Support\Facades\RateLimiter;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use RuntimeException;

/**
 * Verified order-tracking entry point for the `track_order` MCP tool.
 *
 * Dispatches to the per-driver strategy, then enforces the
 * brute-force guards:
 *  - "platforms:order-lookup:{connectionId}" — max 60 lookups / 10 min.
 *  - "platforms:order-lookup:{connectionId}:{sha1(order)}" — max 5
 *    FAILED verifications / hour (unknown order or contact mismatch).
 *    Only failed verifications count; transport problems never do.
 *
 * A locked caller gets the same not-found shape plus locked:true, so
 * the LLM can never learn which half (number vs contact) was wrong.
 */
final class OrderLookup
{
    public function __construct(
        private readonly SallaOrderLookup $salla,
        private readonly ZidOrderLookup $zid,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lookup(PlatformConnection $connection, string $orderNumber, string $contact): array
    {
        $orderNumber = trim($orderNumber);
        $connectionId = (string) $connection->getKey();

        $globalKey = "platforms:order-lookup:{$connectionId}";

        if (RateLimiter::tooManyAttempts($globalKey, 60)) {
            return self::notFound(true);
        }

        RateLimiter::hit($globalKey, 600);

        $orderKey = "platforms:order-lookup:{$connectionId}:".sha1(mb_strtolower($orderNumber));

        if (RateLimiter::tooManyAttempts($orderKey, 5)) {
            return self::notFound(true);
        }

        $connection->loadMissing('platform');
        $driverKey = (string) $connection->platform->driver()->key();

        $outcome = match ($driverKey) {
            'salla' => $this->salla->lookup($connection, $orderNumber, $contact),
            'zid' => $this->zid->lookup($connection, $orderNumber, $contact),
            default => throw new RuntimeException("No order lookup for driver [{$driverKey}]."),
        };

        if ($outcome->countFailure) {
            RateLimiter::hit($orderKey, 3600);
        }

        return $outcome->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function notFound(bool $locked = false): array
    {
        $payload = [
            'found' => false,
            'message' => 'No order matches that number and contact. Ask the visitor to double-check both, or contact the store.',
        ];

        if ($locked) {
            $payload['locked'] = true;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function temporary(): array
    {
        return [
            'found' => false,
            'temporary' => true,
            'message' => 'Order lookup is temporarily unavailable. Please try again shortly.',
        ];
    }
}
