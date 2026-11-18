<?php

namespace Omarsenusi\MarketplacePlatforms\Console;

use Illuminate\Console\Command;
use Omarsenusi\MarketplacePlatforms\Services\WebhookDeliveryService;

class PurgeWebhookDeliveries extends Command
{
    protected $signature = 'platforms:webhooks:purge';

    protected $description = 'Delete expired platform webhook deliveries (retention purge).';

    public function handle(WebhookDeliveryService $service): int
    {
        $deleted = $service->purgeExpired();

        $this->info("Purged {$deleted} expired webhook deliverie(s).");

        return self::SUCCESS;
    }
}
