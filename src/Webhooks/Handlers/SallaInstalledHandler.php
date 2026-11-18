<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Handlers;

use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Services\Provisioner;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;

/**
 * Salla app.installed: ensure the connection row exists
 * (pending_oauth). Idempotent — and must never downgrade an active
 * connection (authorize may arrive first).
 */
final class SallaInstalledHandler implements WebhookHandler
{
    public function __construct(private readonly Provisioner $provisioner) {}

    public function handle(WebhookContext $ctx): void
    {
        $storeId = $this->storeId($ctx);

        if ($storeId === null) {
            throw new PermanentWebhookException('Salla installed payload has no merchant id.');
        }

        $this->provisioner->provision(
            $ctx->platform,
            new StoreProfile(externalStoreId: $storeId),
            null,
            'salla.installed',
        );
    }

    private function storeId(WebhookContext $ctx): ?string
    {
        if (is_string($ctx->storeId) && $ctx->storeId !== '') {
            return $ctx->storeId;
        }

        $payload = is_array($ctx->payload) ? $ctx->payload : [];
        $merchant = $payload['merchant'] ?? null;

        return is_scalar($merchant) && (string) $merchant !== '' ? (string) $merchant : null;
    }
}
