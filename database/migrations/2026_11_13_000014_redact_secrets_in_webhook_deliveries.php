<?php

use Illuminate\Database\Migrations\Migration;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Webhooks\PayloadRedactor;

/**
 * One-off scrub: deliveries stored before PayloadRedactor existed kept
 * Salla authorize tokens (data.access_token / refresh_token) in the
 * payload column. Final rows no longer need them; failed / dead-letter
 * rows keep them for retry (redacted on display only).
 */
return new class extends Migration
{
    public function up(): void
    {
        PlatformWebhookDelivery::query()
            ->whereIn('status', ['processed', 'rejected', 'ignored'])
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(200, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $scrubbed = PayloadRedactor::redact($delivery->payload);

                    if ($scrubbed !== $delivery->payload) {
                        $delivery->forceFill(['payload' => $scrubbed])->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design: redacted secrets are not restorable.
    }
};
