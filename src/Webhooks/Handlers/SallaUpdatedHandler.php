<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Handlers;

use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Services\Provisioner;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;

/**
 * Salla app.updated: the store profile may have changed (domain,
 * name, owner). Re-fetch and refresh settings
 * only (Provisioner::refreshProfile) — never reactivate or mail, as
 * this event fires for every store on each app release. Unknown or
 * uninstalled stores are just logged.
 */
final class SallaUpdatedHandler implements WebhookHandler
{
    public function __construct(private readonly Provisioner $provisioner) {}

    public function handle(WebhookContext $ctx): void
    {
        $connection = $ctx->connection;

        if ($connection === null || $connection->uninstalled_at !== null) {
            Log::info('platforms.salla.updated', [
                'platform_id' => $ctx->platform->id,
                'store_id' => $ctx->storeId,
            ]);

            return;
        }

        try {
            $profile = $ctx->platform->driver()->fetchStoreProfile($connection->refresh());
        } catch (\Throwable $e) {
            Log::warning('platforms.salla.updated_profile_failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Pin to the connection's store id: the row already exists
        // under it and reinstalls must reuse it.
        $profile = new StoreProfile(
            externalStoreId: $connection->external_store_id,
            storeName: $profile->storeName,
            storeDomain: $profile->storeDomain,
            storeUrl: $profile->storeUrl,
            ownerName: $profile->ownerName,
            ownerEmail: $profile->ownerEmail,
            ownerPhone: $profile->ownerPhone,
            currency: $profile->currency,
            locale: $profile->locale,
            metadata: $profile->metadata,
        );

        $this->provisioner->refreshProfile($connection, $profile);
    }
}
