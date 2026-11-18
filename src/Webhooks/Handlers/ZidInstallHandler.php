<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Handlers;

use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Services\Provisioner;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;

/**
 * Zid app.market.application.install / .authorized: ensure a
 * pending_oauth connection. Owner email/name from the payload seed
 * the settings placeholders; the user/workspace are created at the
 * OAuth callback (or immediately when an email is present).
 */
final class ZidInstallHandler implements WebhookHandler
{
    public function __construct(private readonly Provisioner $provisioner) {}

    public function handle(WebhookContext $ctx): void
    {
        $storeId = $this->storeId($ctx);

        if ($storeId === null) {
            throw new PermanentWebhookException('Zid install payload has no store id.');
        }

        $payload = is_array($ctx->payload) ? $ctx->payload : [];

        $this->provisioner->provision(
            $ctx->platform,
            new StoreProfile(
                externalStoreId: $storeId,
                storeName: $this->string($payload, ['store.name', 'store.title', 'store_name']),
                ownerName: $this->string($payload, ['owner.name', 'owner_name', 'user.name']),
                ownerEmail: $this->string($payload, ['owner.email', 'owner_email', 'user.email', 'email']),
                ownerPhone: $this->string($payload, ['owner.mobile', 'owner.phone', 'user.mobile']),
            ),
            null,
            $ctx->topic ?? 'zid.install',
        );
    }

    private function storeId(WebhookContext $ctx): ?string
    {
        if (is_string($ctx->storeId) && $ctx->storeId !== '') {
            return $ctx->storeId;
        }

        $payload = is_array($ctx->payload) ? $ctx->payload : [];

        foreach (['store_id', 'store.id', 'storeId'] as $key) {
            $value = data_get($payload, $key);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $keys
     */
    private function string(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
