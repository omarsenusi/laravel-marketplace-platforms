<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Handlers;

use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Auth\TokenSet;
use Omarsenusi\MarketplacePlatforms\Services\Provisioner;
use Omarsenusi\MarketplacePlatforms\Webhooks\PayloadRedactor;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;

/**
 * Salla Easy-mode install: tokens arrive in the webhook
 * (data.{access_token, refresh_token, expires, scope}) and the store
 * id arrives top-level as `merchant`.
 */
final class SallaAuthorizeHandler implements WebhookHandler
{
    public function __construct(private readonly Provisioner $provisioner) {}

    public function handle(WebhookContext $ctx): void
    {
        $payload = is_array($ctx->payload) ? $ctx->payload : [];
        $storeId = $ctx->storeId ?? (is_scalar($payload['merchant'] ?? null) ? (string) $payload['merchant'] : null);

        if ($storeId === null || $storeId === '') {
            throw new PermanentWebhookException('Salla authorize payload has no merchant id.');
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new PermanentWebhookException('Salla authorize payload has no tokens.');
        }

        // A scrubbed (already-processed) payload must never overwrite
        // live tokens with the redaction mask.
        if ($data['access_token'] === PayloadRedactor::MASK || $data['refresh_token'] === PayloadRedactor::MASK) {
            throw new PermanentWebhookException('Salla authorize payload tokens were redacted.');
        }

        // `expires` is an ABSOLUTE unix timestamp (seconds) — parsed
        // by TokenSet::fromSallaResponse, never added to now().
        $tokenSet = TokenSet::fromSallaResponse($data);

        // First persist the connection + tokens (idempotent by
        // platform + store), so the profile fetch below always has an
        // authorized connection to call with.
        $result = $this->provisioner->provision(
            $ctx->platform,
            new StoreProfile(externalStoreId: $storeId),
            $tokenSet,
            'salla.authorize',
        );

        // Then fetch real owner/store data via user/info. On failure the
        // tokens + schedule from the first step stand; a later event or a
        // manual profile re-sync backfills.
        try {
            $profile = $ctx->platform->driver()->fetchStoreProfile($result->connection->refresh());
        } catch (\Throwable $e) {
            Log::warning('platforms.salla.profile_failed', [
                'connection_id' => $result->connection->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Pin to the webhook's store id: the connection row already
        // exists under it and reinstalls must reuse it.
        $profile = new StoreProfile(
            externalStoreId: $storeId,
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

        $this->provisioner->provision($ctx->platform, $profile, null, 'salla.authorize');
    }
}
