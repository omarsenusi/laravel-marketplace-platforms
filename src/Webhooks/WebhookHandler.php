<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks;

interface WebhookHandler
{
    /**
     * @throws PermanentWebhookException when the failure is not retryable
     *                                   (bad payload shape, unknown store, permanent upstream rejection).
     */
    public function handle(WebhookContext $ctx): void;
}
