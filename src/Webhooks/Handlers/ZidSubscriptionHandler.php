<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Handlers;

use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Billing\SubscriptionSync;
use Omarsenusi\MarketplacePlatforms\Services\Provisioner;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;

/**
 * Zid app.market.subscription.*: parse the flat payload and apply it.
 *
 * subscription.active often arrives BEFORE application.install, so
 * an unknown store with pending work (active/trialing) gets the same
 * pending_oauth connection ZidInstallHandler creates — the OAuth
 * callback attaches the workspace later and applyStored() finishes
 * the job. Terminal states for unknown stores are just logged.
 */
final class ZidSubscriptionHandler implements WebhookHandler
{
    public function __construct(
        private readonly SubscriptionSync $sync,
        private readonly Provisioner $provisioner,
    ) {}

    public function handle(WebhookContext $ctx): void
    {
        $payload = is_array($ctx->payload) ? $ctx->payload : [];
        $parsed = $ctx->platform->driver()->parseSubscriptionEvent($payload);

        if ($parsed === null) {
            Log::info('platforms.subscription.ignored', [
                'platform_id' => $ctx->platform->id,
                'store_id' => $ctx->storeId,
                'topic' => $ctx->topic,
            ]);

            $ctx->note('Ignored: not a plan subscription event (e.g. an addon), nothing to apply.');

            return;
        }

        $connection = $ctx->connection;

        if ($connection === null) {
            $storeId = $this->storeId($ctx);

            if ($storeId === null) {
                throw new PermanentWebhookException('Zid subscription payload has no store id.');
            }

            if (! in_array($parsed->state, ['active', 'trialing'], true)) {
                Log::info('platforms.subscription.unknown_store_terminal', [
                    'platform_id' => $ctx->platform->id,
                    'store_id' => $storeId,
                    'topic' => $ctx->topic,
                ]);

                $ctx->note('Recorded only: lapse for a store that is not connected.');

                return;
            }

            $result = $this->provisioner->provision(
                $ctx->platform,
                new StoreProfile(externalStoreId: $storeId),
                null,
                $ctx->topic ?? 'zid.subscription',
            );

            $connection = $result->connection->refresh();
        }

        $outcome = $this->sync->apply($connection, $parsed);
        $ctx->note($outcome->message, $outcome->needsAction);
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
}
