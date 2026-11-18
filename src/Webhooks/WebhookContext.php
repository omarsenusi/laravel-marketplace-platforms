<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks;

use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookEndpoint;

/**
 * Everything a business handler needs. Populated by
 * ProcessPlatformWebhook from the stored delivery row.
 */
final class WebhookContext
{
    public function __construct(
        public readonly Platform $platform,
        public readonly PlatformWebhookEndpoint $endpoint,
        public readonly PlatformWebhookDelivery $delivery,
        public readonly ?PlatformConnection $connection,
        public readonly ?string $topic,
        public readonly ?string $storeId,
        /** @var array<string, mixed>|null */
        public readonly ?array $payload,
    ) {}

    /**
     * Record what the handler actually did (stored next to the final
     * status, so a no-op never looks like a success). Persisted by
     * ProcessPlatformWebhook with the delivery's final status.
     */
    public function note(string $outcome, bool $needsAction = false): void
    {
        $this->delivery->outcome = mb_substr(trim($outcome), 0, 500);
        $this->delivery->needs_action = $needsAction;
    }
}
