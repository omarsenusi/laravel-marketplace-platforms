<?php

namespace Omarsenusi\MarketplacePlatforms\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Enums\DeliveryStatus;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookEndpoint;
use Omarsenusi\MarketplacePlatforms\Webhooks\HeaderRedactor;
use Omarsenusi\MarketplacePlatforms\Webhooks\PayloadRedactor;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\PostValidation;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\SecretResolver;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\StrategyRegistry;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;

/**
 * Shared inbound-webhook pipeline. The public ingress controller and
 * programmatic callers (tests, host tooling) both funnel through
 * here; the only difference is $skipVerification.
 */
class WebhookIngress
{
    public function __construct(
        private readonly StrategyRegistry $strategies,
    ) {}

    /**
     * @param  array<string, string>  $headers  Lower-cased header name => single value.
     * @return array{http: int, body: array<string, mixed>, delivery: PlatformWebhookDelivery}
     */
    public function handle(
        Platform $platform,
        PlatformWebhookEndpoint $endpoint,
        string $rawBody,
        array $headers,
        bool $skipVerification = false,
    ): array {
        $verificationConfig = $endpoint->verification_config ?? [];
        $redactedHeaders = HeaderRedactor::redact($headers, is_array($verificationConfig) ? $verificationConfig : []);

        $payload = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($payload)) {
            $delivery = $this->store($platform, $endpoint, [
                'external_event_id' => $this->unclaimedEventId('invalid', hash('sha256', $rawBody)),
                'topic' => null,
                'headers' => $redactedHeaders,
                'payload' => null,
                'status' => DeliveryStatus::Rejected,
                'failure_reason' => 'invalid_json',
                'max_attempts' => $endpoint->max_retries,
                'received_at' => now(),
                'expires_at' => now()->addSeconds($endpoint->log_retention_seconds),
            ]);

            return ['http' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json'], 'delivery' => $delivery];
        }

        /** @var array<string, mixed> $payload */
        if (! $skipVerification) {
            $result = $this->strategies
                ->resolve($endpoint->verification_strategy)
                ->verify(new VerificationContext(
                    $rawBody,
                    $headers,
                    is_array($verificationConfig) ? $verificationConfig : [],
                    SecretResolver::for($platform),
                ));

            if (! $result->valid) {
                $delivery = $this->store($platform, $endpoint, [
                    'external_event_id' => $this->unclaimedEventId('rejected', $this->eventId($platform, $endpoint, $payload, $rawBody)),
                    'topic' => $this->topic($platform, $payload),
                    'headers' => $redactedHeaders,
                    'payload' => PayloadRedactor::redact($payload),
                    'status' => DeliveryStatus::Rejected,
                    'failure_reason' => $result->reason ?? 'verification_failed',
                    'max_attempts' => $endpoint->max_retries,
                    'received_at' => now(),
                    'expires_at' => now()->addSeconds($endpoint->log_retention_seconds),
                ]);

                return ['http' => 401, 'body' => ['ok' => false], 'delivery' => $delivery];
            }

            $postReason = PostValidation::check(
                $platform,
                $payload,
                is_array($verificationConfig) ? ($verificationConfig['postValidation'] ?? null) : null,
            );

            if ($postReason !== null) {
                $delivery = $this->store($platform, $endpoint, [
                    'external_event_id' => $this->unclaimedEventId('rejected', $this->eventId($platform, $endpoint, $payload, $rawBody)),
                    'topic' => $this->topic($platform, $payload),
                    'headers' => $redactedHeaders,
                    'payload' => PayloadRedactor::redact($payload),
                    'status' => DeliveryStatus::Rejected,
                    'failure_reason' => $postReason,
                    'max_attempts' => $endpoint->max_retries,
                    'received_at' => now(),
                    'expires_at' => now()->addSeconds($endpoint->log_retention_seconds),
                ]);

                return ['http' => 401, 'body' => ['ok' => false], 'delivery' => $delivery];
            }
        }

        $driver = $platform->driver();
        $driverConfig = is_array($platform->config) ? $platform->config : [];
        $topic = $driver->topic($payload, $driverConfig);
        $storeId = $driver->storeId($payload, $driverConfig);
        $externalEventId = $driver->externalEventId($payload, $rawBody, $driverConfig);

        $topicFilter = $endpoint->topic_filter ?? [];

        if (is_array($topicFilter) && $topicFilter !== [] && ! in_array($topic, $topicFilter, true)) {
            $delivery = $this->store($platform, $endpoint, [
                'external_event_id' => $this->unclaimedEventId('ignored', $externalEventId),
                'topic' => $topic,
                'headers' => $redactedHeaders,
                'payload' => PayloadRedactor::redact($payload),
                'status' => DeliveryStatus::Ignored,
                'failure_reason' => null,
                'max_attempts' => $endpoint->max_retries,
                'received_at' => now(),
                'expires_at' => now()->addSeconds($endpoint->log_retention_seconds),
            ]);

            return ['http' => 200, 'body' => ['ok' => true, 'ignored' => true], 'delivery' => $delivery];
        }

        $connectionId = $storeId !== null
            ? PlatformConnection::query()
                ->where('platform_id', $platform->id)
                ->where('external_store_id', $storeId)
                ->value('id')
            : null;

        try {
            $delivery = $this->store($platform, $endpoint, [
                'connection_id' => $connectionId,
                'external_event_id' => $externalEventId,
                'topic' => $topic,
                'headers' => $redactedHeaders,
                'payload' => $payload,
                'status' => DeliveryStatus::Pending,
                'failure_reason' => null,
                'max_attempts' => $endpoint->max_retries,
                'received_at' => now(),
                'expires_at' => now()->addSeconds($endpoint->log_retention_seconds),
            ]);
        } catch (QueryException $e) {
            // Unique (endpoint_id, external_event_id) — a redelivery of an
            // already-stored event. Acknowledge without re-dispatching.
            $existing = PlatformWebhookDelivery::query()
                ->where('endpoint_id', $endpoint->id)
                ->where('external_event_id', $externalEventId)
                ->first();

            if ($existing !== null) {
                return ['http' => 200, 'body' => ['ok' => true, 'duplicate' => true], 'delivery' => $existing];
            }

            throw $e;
        }

        ProcessPlatformWebhook::dispatch($delivery->id)
            ->onQueue((string) config('platforms.webhooks.queue', 'default'))
            ->afterCommit();

        return ['http' => 200, 'body' => ['ok' => true], 'delivery' => $delivery];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function topic(Platform $platform, array $payload): ?string
    {
        $config = is_array($platform->config) ? $platform->config : [];

        return $platform->driver()->topic($payload, $config);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventId(Platform $platform, PlatformWebhookEndpoint $endpoint, array $payload, string $rawBody): string
    {
        $config = is_array($platform->config) ? $platform->config : [];

        return $platform->driver()->externalEventId($payload, $rawBody, $config);
    }

    /**
     * Log-only rows (rejected / invalid / ignored) must NOT occupy the real
     * event id in the (endpoint_id, external_event_id) unique index:
     * otherwise a forged request could pre-claim a genuine event's id, and
     * a legitimate redelivery after the host fixes a secret would be
     * swallowed as a "duplicate". Keep the real id visible for debugging
     * but make the stored value unique per row.
     */
    private function unclaimedEventId(string $kind, string $eventId): string
    {
        return $kind.':'.substr($eventId, 0, 160).':'.Str::ulid();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(Platform $platform, PlatformWebhookEndpoint $endpoint, array $attributes): PlatformWebhookDelivery
    {
        return PlatformWebhookDelivery::query()->create(array_merge($attributes, [
            'platform_id' => $platform->id,
            'endpoint_id' => $endpoint->id,
        ]));
    }
}
