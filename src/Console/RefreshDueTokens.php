<?php

namespace Omarsenusi\MarketplacePlatforms\Console;

use Illuminate\Console\Command;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Jobs\RefreshPlatformToken;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Dispatch RefreshPlatformToken for active connections whose
 * refresh_scheduled_at is due. Runs every 15 minutes (see provider
 * schedule) with withoutOverlapping.
 */
class RefreshDueTokens extends Command
{
    protected $signature = 'platforms:tokens:refresh-due';

    protected $description = 'Dispatch token refreshes for due platform connections';

    public function handle(): int
    {
        $ids = PlatformConnection::query()
            ->where('status', ConnectionStatus::Active)
            ->whereNotNull('refresh_scheduled_at')
            ->where('refresh_scheduled_at', '<=', now())
            ->pluck('id');

        foreach ($ids as $id) {
            RefreshPlatformToken::dispatch((string) $id);
        }

        $this->info("Dispatched {$ids->count()} token refresh(es).");

        return self::SUCCESS;
    }
}
