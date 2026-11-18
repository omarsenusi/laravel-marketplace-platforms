<?php

namespace Omarsenusi\MarketplacePlatforms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omarsenusi\MarketplacePlatforms\Billing\SubscriptionSync;
use Omarsenusi\MarketplacePlatforms\Models\Platform;

/**
 * Bulk re-apply of stored subscription snapshots after the host
 * adds plan mappings (each connection handled inline). Each connection is handled by SubscriptionSync, which
 * skips anything already applied or out of scope.
 */
class ReapplyPlatformSubscriptions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $platformId,
    ) {}

    public function handle(SubscriptionSync $sync): void
    {
        $platform = Platform::query()->find($this->platformId);

        if ($platform === null) {
            return;
        }

        $platform->connections()->with('storeSettings')->orderBy('id')->chunkById(100, function ($connections) use ($sync): void {
            foreach ($connections as $connection) {
                $stored = is_array($connection->subscription) ? $connection->subscription : null;

                if ($stored === null || ! in_array($stored['state'] ?? null, ['active', 'trialing'], true)) {
                    continue;
                }

                if (($stored['applied'] ?? false) === true && ($stored['mapped_plan_id'] ?? null) !== null) {
                    continue;
                }

                $sync->applyStored($connection);
            }
        });
    }
}
