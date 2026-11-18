<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Handlers;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Services\Uninstaller;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;

/**
 * Zid app.market.application.uninstall: same semantics as Salla —
 * tokens cleared, claims expired, user + workspace kept.
 */
final class ZidUninstallHandler implements WebhookHandler
{
    public function __construct(private readonly Uninstaller $uninstaller) {}

    public function handle(WebhookContext $ctx): void
    {
        $connection = $ctx->connection ?? $this->findConnection($ctx);

        if ($connection === null) {
            throw new PermanentWebhookException('Zid uninstall for unknown store.');
        }

        $this->uninstaller->uninstall($connection, 'zid.uninstall');
    }

    private function findConnection(WebhookContext $ctx): ?PlatformConnection
    {
        $storeId = $ctx->storeId;

        if (! is_string($storeId) || $storeId === '') {
            return null;
        }

        return PlatformConnection::query()
            ->where('platform_id', $ctx->platform->id)
            ->where('external_store_id', $storeId)
            ->first();
    }
}
