<?php

namespace Omarsenusi\MarketplacePlatforms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Fired for every successfully processed webhook delivery — including
 * topics with no registered handler — so the host can react without
 * registering a handler.
 */
final class PlatformWebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $platformSlug,
        public readonly string $driverKey,
        public readonly ?string $topic,
        public readonly ?string $storeId,
        public readonly array $payload,
        public readonly ?PlatformConnection $connection = null,
    ) {}
}
