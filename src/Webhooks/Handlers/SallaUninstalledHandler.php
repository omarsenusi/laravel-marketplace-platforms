<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Handlers;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Services\Uninstaller;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;

/**
 * Salla app.uninstalled: clear tokens, expire claims, keep user +
 * workspace. Unknown stores are a permanent failure (nothing to do).
 */
final class SallaUninstalledHandler implements WebhookHandler
{
    public function __construct(private readonly Uninstaller $uninstaller) {}

    public function handle(WebhookContext $ctx): void
    {
        $connection = $ctx->connection ?? $this->findConnection($ctx);

        if ($connection === null) {
            throw new PermanentWebhookException('Salla uninstall for unknown store.');
        }

        $this->uninstaller->uninstall($connection, 'salla.uninstalled');
    }

    private function findConnection(WebhookContext $ctx): ?PlatformConnection
    {
        $storeId = $ctx->storeId;

        if (! is_string($storeId) || $storeId === '') {
            $payload = is_array($ctx->payload) ? $ctx->payload : [];
            $merchant = $payload['merchant'] ?? null;
            $storeId = is_scalar($merchant) ? (string) $merchant : null;
        }

        if (! is_string($storeId) || $storeId === '') {
            return null;
        }

        return PlatformConnection::query()
            ->where('platform_id', $ctx->platform->id)
            ->where('external_store_id', $storeId)
            ->first();
    }
}
