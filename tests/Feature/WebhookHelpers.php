<?php

use Omarsenusi\MarketplacePlatforms\Enums\DeliveryStatus;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookEndpoint;

function makeWebhookPlatform(array $overrides = [], array $secrets = []): Platform
{
    $platform = Platform::factory()->create($overrides);

    $platform->secrets()->create(array_merge([
        'client_secret' => 'test-client-secret',
        'webhook_secret' => 'test-webhook-secret',
        'partner_api_key' => 'test-partner-key',
    ], $secrets));

    return $platform->fresh();
}

function makeWebhookEndpoint(Platform $platform, array $overrides = []): PlatformWebhookEndpoint
{
    return PlatformWebhookEndpoint::create(array_merge([
        'platform_id' => $platform->id,
        'name' => 'Test endpoint',
        'key' => 'test-'.str()->random(6),
        'verification_strategy' => $platform->driver()->defaultWebhookVerification()['strategy'],
        'verification_config' => $platform->driver()->defaultWebhookVerification()['config'] ?? [],
        'log_retention_seconds' => 2592000,
        'max_retries' => 5,
        'topic_filter' => [],
        'enabled' => true,
    ], $overrides));
}

function makeWebhookDelivery(PlatformWebhookEndpoint $endpoint, array $overrides = []): PlatformWebhookDelivery
{
    return PlatformWebhookDelivery::create(array_merge([
        'platform_id' => $endpoint->platform_id,
        'endpoint_id' => $endpoint->id,
        'external_event_id' => 'evt-'.str()->random(8),
        'topic' => 'app.test',
        'headers' => ['content-type' => 'application/json'],
        'payload' => ['event' => 'app.test', 'merchant' => 'store-1'],
        'status' => DeliveryStatus::Pending,
        'attempt_count' => 0,
        'max_attempts' => 5,
        'received_at' => now(),
        'expires_at' => now()->addDays(30),
    ], $overrides));
}
