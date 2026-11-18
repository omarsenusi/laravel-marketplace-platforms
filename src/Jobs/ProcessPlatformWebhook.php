<?php

namespace Omarsenusi\MarketplacePlatforms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Enums\DeliveryStatus;
use Omarsenusi\MarketplacePlatforms\Events\PlatformWebhookReceived;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Webhooks\PayloadRedactor;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;
use Throwable;

/**
 * Async webhook processing. Retries are managed here (not by the queue):
 * $tries = 1, and failures re-dispatch with a computed delay until
 * max_attempts is exhausted. Never logs payload secrets — only ids.
 */
class ProcessPlatformWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The queue worker must not retry: backoff + dead-lettering below is
     * the retry policy.
     */
    public int $tries = 1;

    /**
     * Backoff seconds per attempt (1-based attempt index, clamped to last).
     *
     * @var list<int>
     */
    public const BACKOFF_SECONDS = [30, 120, 600, 3600, 14400];

    public function __construct(
        public readonly string $deliveryId,
    ) {}

    public function handle(WebhookHandlerRegistry $registry): void
    {
        $delivery = PlatformWebhookDelivery::query()
            ->with(['platform', 'endpoint', 'connection'])
            ->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        if (! in_array($delivery->status, [DeliveryStatus::Pending, DeliveryStatus::Failed], true)) {
            return;
        }

        $startedAt = microtime(true);

        $delivery->forceFill([
            'status' => DeliveryStatus::Processing,
            'processing_started_at' => now(),
            // Fresh attempt: the handler re-records what it did.
            'outcome' => null,
            'needs_action' => false,
            'attempt_count' => $delivery->attempt_count + 1,
        ])->save();

        try {
            $platform = $delivery->platform;
            $endpoint = $delivery->endpoint;
            $config = is_array($platform->config) ? $platform->config : [];
            $payload = is_array($delivery->payload) ? $delivery->payload : [];

            $storeId = $platform->driver()->storeId($payload, $config);

            $handler = $registry->resolve($platform->driver, $delivery->topic);

            $handler->handle(new WebhookContext(
                $platform,
                $endpoint,
                $delivery,
                $delivery->connection,
                $delivery->topic,
                $storeId,
                $payload,
            ));

            $delivery->forceFill([
                'status' => DeliveryStatus::Processed,
                // Final: the handler has consumed any credentials in the
                // body (Salla authorize tokens) — never keep them at rest.
                'payload' => PayloadRedactor::redact($delivery->payload),
                'processed_at' => now(),
                'failure_reason' => null,
                'next_retry_at' => null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ])->save();

            PlatformWebhookReceived::dispatch(
                $platform->slug,
                $platform->driver()->key(),
                $delivery->topic,
                $storeId,
                $payload,
                $delivery->connection,
            );
        } catch (PermanentWebhookException $e) {
            $delivery->forceFill([
                'status' => DeliveryStatus::DeadLetter,
                'failure_reason' => mb_substr($e->getMessage(), 0, 2000),
                'next_retry_at' => null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ])->save();

            Log::warning('platform.webhook.dead_letter', [
                'delivery_id' => $delivery->id,
                'topic' => $delivery->topic,
                'reason' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $delivery->refresh();

            $attempts = $delivery->attempt_count;
            $maxAttempts = $delivery->max_attempts;

            if ($attempts < $maxAttempts) {
                $delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];
                $nextRetryAt = now()->addSeconds($delay);

                $delivery->forceFill([
                    'status' => DeliveryStatus::Failed,
                    'failure_reason' => mb_substr($e->getMessage(), 0, 2000),
                    'next_retry_at' => $nextRetryAt,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ])->save();

                Log::warning('platform.webhook.failed', [
                    'delivery_id' => $delivery->id,
                    'topic' => $delivery->topic,
                    'attempt' => $attempts,
                    'next_retry_at' => $nextRetryAt->toIso8601String(),
                    'error' => $e->getMessage(),
                ]);

                self::dispatch($delivery->id)
                    ->onQueue((string) config('platforms.webhooks.queue', 'default'))
                    ->delay($nextRetryAt);
            } else {
                $delivery->forceFill([
                    'status' => DeliveryStatus::DeadLetter,
                    'failure_reason' => mb_substr($e->getMessage(), 0, 2000),
                    'next_retry_at' => null,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ])->save();

                Log::warning('platform.webhook.dead_letter', [
                    'delivery_id' => $delivery->id,
                    'topic' => $delivery->topic,
                    'attempts' => $attempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
