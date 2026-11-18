<?php

namespace Omarsenusi\MarketplacePlatforms\Services;

use Omarsenusi\MarketplacePlatforms\Enums\DeliveryStatus;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Webhooks\PayloadRedactor;

/**
 * Operations on stored deliveries: manual retry and retention purge.
 *
 * Retryable: failed, dead_letter, and processed (re-run a handler after
 * fixing config, e.g. adding a plan mapping). Never rejected (failed
 * verification) or ignored rows, and never a processed row whose
 * credentials were scrubbed (a replay could not succeed).
 */
class WebhookDeliveryService
{
    public static function canRetry(PlatformWebhookDelivery $delivery): bool
    {
        return match ($delivery->status) {
            DeliveryStatus::Failed, DeliveryStatus::DeadLetter => true,
            DeliveryStatus::Processed => ! self::hasRedactedSecrets($delivery),
            default => false,
        };
    }

    /**
     * Reset a delivery back to pending and re-dispatch it. Returns false
     * when the delivery is not in a retryable state.
     */
    public function retry(PlatformWebhookDelivery $delivery): bool
    {
        if (! self::canRetry($delivery)) {
            return false;
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::Pending,
            'failure_reason' => null,
            'outcome' => null,
            'needs_action' => false,
            'next_retry_at' => null,
            'processed_at' => null,
        ])->save();

        ProcessPlatformWebhook::dispatch($delivery->id)
            ->onQueue((string) config('platforms.webhooks.queue', 'default'))
            ->afterCommit();

        return true;
    }

    /**
     * Delete expired deliveries in 1000-row chunks. Returns total deleted.
     */
    public function purgeExpired(): int
    {
        $deleted = 0;

        do {
            $ids = PlatformWebhookDelivery::query()
                ->where('expires_at', '<', now())
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += PlatformWebhookDelivery::query()->whereKey($ids)->delete();
        } while (true);

        return $deleted;
    }

    private static function hasRedactedSecrets(PlatformWebhookDelivery $delivery): bool
    {
        $encoded = json_encode($delivery->payload);

        return is_string($encoded) && str_contains($encoded, '"'.PayloadRedactor::MASK.'"');
    }
}
