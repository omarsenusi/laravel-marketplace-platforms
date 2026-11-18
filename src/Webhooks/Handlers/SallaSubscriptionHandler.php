<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Handlers;

use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Billing\SubscriptionSync;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;

/**
 * Salla app.subscription.* + app.trial.*: parse the payload into a
 * normalized event and apply it (plan assign / lapse / store).
 *
 * Unknown stores are retryable (a matching install may still
 * arrive): a generic exception re-queues with backoff instead of
 * dead-lettering.
 */
final class SallaSubscriptionHandler implements WebhookHandler
{
    public function __construct(private readonly SubscriptionSync $sync) {}

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
            $merchant = $payload['merchant'] ?? null;
            $storeId = is_scalar($merchant) && (string) $merchant !== '' ? (string) $merchant : $ctx->storeId;

            if (! is_string($storeId) || $storeId === '') {
                throw new PermanentWebhookException('Salla subscription payload has no merchant id.');
            }

            // The install/authorize may simply not have been processed
            // yet — retry with backoff rather than dead-lettering.
            throw new \RuntimeException("Salla subscription for unknown store {$storeId}; install may still arrive.");
        }

        $outcome = $this->sync->apply($connection, $parsed);
        $ctx->note($outcome->message, $outcome->needsAction);
    }
}
