<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks;

use Illuminate\Support\Facades\Log;

/**
 * Default handler: no business logic registered for this topic.
 * Logs and marks the delivery processed.
 */
class NullHandler implements WebhookHandler
{
    public function handle(WebhookContext $ctx): void
    {
        Log::info('platform.webhook.unhandled', [
            'platform' => $ctx->platform->slug,
            'endpoint' => $ctx->endpoint->key,
            'delivery_id' => $ctx->delivery->id,
            'topic' => $ctx->topic,
            'store_id' => $ctx->storeId,
        ]);
    }
}
