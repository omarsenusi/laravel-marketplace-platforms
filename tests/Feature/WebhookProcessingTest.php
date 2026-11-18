<?php

require_once __DIR__.'/WebhookHelpers.php';

use Illuminate\Support\Facades\Queue;
use Omarsenusi\MarketplacePlatforms\Enums\DeliveryStatus;
use Omarsenusi\MarketplacePlatforms\Jobs\ProcessPlatformWebhook;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;
use Omarsenusi\MarketplacePlatforms\Webhooks\NullHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;

class RecordsHandledTopics implements WebhookHandler
{
    /** @var list<string|null> */
    public static array $handled = [];

    public static ?string $throw = null;

    public function handle(WebhookContext $ctx): void
    {
        static::$handled[] = $ctx->topic;

        if (static::$throw === 'permanent') {
            throw new PermanentWebhookException('store gone');
        }

        if (static::$throw === 'generic') {
            throw new RuntimeException('boom');
        }
    }
}

beforeEach(function () {
    RecordsHandledTopics::$handled = [];
    RecordsHandledTopics::$throw = null;
});

function runJob(PlatformWebhookDelivery $delivery): void
{
    (new ProcessPlatformWebhook($delivery->id))->handle(app(WebhookHandlerRegistry::class));
}

test('job success marks delivery processed with duration', function () {
    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint);

    app(WebhookHandlerRegistry::class)->register('salla', 'app.*', RecordsHandledTopics::class);

    runJob($delivery);

    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Processed)
        ->and($delivery->processed_at)->not->toBeNull()
        ->and($delivery->duration_ms)->not->toBeNull()
        ->and(RecordsHandledTopics::$handled)->toBe(['app.test']);
});

test('unmatched topics fall back to the null handler', function () {
    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint, ['topic' => 'something.else']);

    runJob($delivery);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Processed);
    expect(app(WebhookHandlerRegistry::class)->resolve('salla', 'something.else'))->toBeInstanceOf(NullHandler::class);
});

test('generic exception marks failed with next_retry_at and re-dispatches with delay', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint, ['max_attempts' => 5]);

    app(WebhookHandlerRegistry::class)->register('salla', '*', RecordsHandledTopics::class);
    RecordsHandledTopics::$throw = 'generic';

    runJob($delivery);

    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($delivery->failure_reason)->toBe('boom');

    Queue::assertPushed(ProcessPlatformWebhook::class, 1);
});

test('exhausted attempts go straight to dead_letter', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint, ['max_attempts' => 2, 'attempt_count' => 1]);

    app(WebhookHandlerRegistry::class)->register('salla', '*', RecordsHandledTopics::class);
    RecordsHandledTopics::$throw = 'generic';

    runJob($delivery);

    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::DeadLetter)
        ->and($delivery->attempt_count)->toBe(2)
        ->and($delivery->next_retry_at)->toBeNull();

    Queue::assertNotPushed(ProcessPlatformWebhook::class);
});

test('permanent exception goes straight to dead_letter', function () {
    Queue::fake();

    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);
    $delivery = makeWebhookDelivery($endpoint);

    app(WebhookHandlerRegistry::class)->register('salla', '*', RecordsHandledTopics::class);
    RecordsHandledTopics::$throw = 'permanent';

    runJob($delivery);

    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::DeadLetter)
        ->and($delivery->failure_reason)->toBe('store gone');

    Queue::assertNotPushed(ProcessPlatformWebhook::class);
});

test('job skips deliveries that are not pending or failed', function () {
    $platform = makeWebhookPlatform(['driver' => 'salla']);
    $endpoint = makeWebhookEndpoint($platform);

    foreach ([DeliveryStatus::Processed, DeliveryStatus::DeadLetter, DeliveryStatus::Rejected, DeliveryStatus::Ignored, DeliveryStatus::Processing] as $status) {
        $delivery = makeWebhookDelivery($endpoint, ['status' => $status, 'external_event_id' => 'evt-'.str()->random(8)]);

        runJob($delivery);

        expect($delivery->refresh()->status)->toBe($status);
    }
});

test('registered handler pattern matching: first match wins', function () {
    $registry = new WebhookHandlerRegistry;
    $registry->register('salla', 'order.*', RecordsHandledTopics::class);
    $registry->register('salla', '*', NullHandler::class);

    expect($registry->resolve('salla', 'order.created'))->toBeInstanceOf(RecordsHandledTopics::class)
        ->and($registry->resolve('salla', 'other'))->toBeInstanceOf(NullHandler::class)
        ->and($registry->resolve('zid', 'order.created'))->toBeInstanceOf(NullHandler::class);
});

test('backoff schedule uses the documented delays', function () {
    expect(ProcessPlatformWebhook::BACKOFF_SECONDS)->toBe([30, 120, 600, 3600, 14400]);
});
