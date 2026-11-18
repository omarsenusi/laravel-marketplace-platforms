<?php

require_once __DIR__.'/WebhookHelpers.php';

use Omarsenusi\MarketplacePlatforms\Drivers\SallaDriver;
use Omarsenusi\MarketplacePlatforms\Drivers\ZidDriver;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\PostValidation;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\SecretResolver;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\StrategyRegistry;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationResult;

function verifyWith(string $strategyId, array $config, string $rawBody, array $headers, array $secrets = []): VerificationResult
{
    $resolver = static fn (string $ref): ?string => match ($ref) {
        'webhook_secret' => $secrets['webhook_secret'] ?? 'topsecret',
        'client_secret' => $secrets['client_secret'] ?? 'client-secret',
        'partner_api_key' => $secrets['partner_api_key'] ?? 'partner-key',
        'app_id' => $secrets['app_id'] ?? 'app_123',
        'client_id' => $secrets['client_id'] ?? 'client_123',
        default => null,
    };

    return app(StrategyRegistry::class)->resolve($strategyId)->verify(
        new VerificationContext($rawBody, $headers, $config, Closure::fromCallable($resolver))
    );
}

test('hmac_sha256 verifies hex and base64, tolerates sha256= prefix', function () {
    $body = '{"event":"order.created"}';
    $secret = 'topsecret';
    $hex = hash_hmac('sha256', $body, $secret);
    $b64 = base64_encode(hash_hmac('sha256', $body, $secret, true));

    $config = ['header' => 'x-salla-signature', 'encoding' => 'hex', 'secretRef' => 'webhook_secret'];

    expect(verifyWith('hmac_sha256', $config, $body, ['x-salla-signature' => $hex])->valid)->toBeTrue()
        ->and(verifyWith('hmac_sha256', $config, $body, ['x-salla-signature' => 'sha256='.$hex])->valid)->toBeTrue()
        ->and(verifyWith('hmac_sha256', $config, $body, ['x-salla-signature' => 'deadbeef'])->valid)->toBeFalse()
        ->and(verifyWith('hmac_sha256', $config, $body, [])->valid)->toBeFalse()
        ->and(verifyWith('hmac_sha256', $config, $body, [], ['webhook_secret' => null])->valid)->toBeFalse();

    $b64config = ['header' => 'x-sig', 'encoding' => 'base64', 'secretRef' => 'webhook_secret'];

    expect(verifyWith('hmac_sha256', $b64config, $body, ['x-sig' => $b64])->valid)->toBeTrue()
        ->and(verifyWith('hmac_sha256', $b64config, $body, ['x-sig' => $hex])->valid)->toBeFalse();
});

test('plain_header compares the header value exactly (Salla token strategy)', function () {
    $config = ['header' => 'authorization', 'secretRef' => 'webhook_secret'];

    expect(verifyWith('plain_header', $config, '{}', ['authorization' => 'topsecret'])->valid)->toBeTrue()
        ->and(verifyWith('plain_header', $config, '{}', ['authorization' => 'Bearer topsecret'])->valid)->toBeFalse()
        ->and(verifyWith('plain_header', $config, '{}', [])->valid)->toBeFalse();
});

test('bearer_token requires the Bearer prefix by default', function () {
    $config = ['header' => 'authorization', 'secretRef' => 'webhook_secret'];

    expect(verifyWith('bearer_token', $config, '{}', ['authorization' => 'Bearer topsecret'])->valid)->toBeTrue()
        ->and(verifyWith('bearer_token', $config, '{}', ['authorization' => 'topsecret'])->valid)->toBeFalse()
        ->and(verifyWith('bearer_token', array_merge($config, ['stripBearerPrefix' => false]), '{}', ['authorization' => 'topsecret'])->valid)->toBeTrue();
});

test('static_header compares against the expected ref (Zid app market)', function () {
    $config = ['header' => 'x-zid-api-key', 'expectedRef' => 'partner_api_key'];

    expect(verifyWith('static_header', $config, '{}', ['x-zid-api-key' => 'partner-key'])->valid)->toBeTrue()
        ->and(verifyWith('static_header', $config, '{}', ['x-zid-api-key' => 'wrong'])->valid)->toBeFalse()
        ->and(verifyWith('static_header', $config, '{}', [])->valid)->toBeFalse();
});

test('basic_auth_password compares the password part', function () {
    $config = ['secretRef' => 'webhook_secret'];
    $good = 'Basic '.base64_encode('anyuser:topsecret');
    $bad = 'Basic '.base64_encode('anyuser:wrong');

    expect(verifyWith('basic_auth_password', $config, '{}', ['authorization' => $good])->valid)->toBeTrue()
        ->and(verifyWith('basic_auth_password', $config, '{}', ['authorization' => $bad])->valid)->toBeFalse()
        ->and(verifyWith('basic_auth_password', $config, '{}', ['authorization' => 'topsecret'])->valid)->toBeFalse()
        ->and(verifyWith('basic_auth_password', $config, '{}', ['authorization' => 'Basic !!!'])->valid)->toBeFalse();
});

test('composite_any succeeds when any child succeeds and lists reasons otherwise', function () {
    $body = '{"event":"x"}';
    $hex = hash_hmac('sha256', $body, 'topsecret');

    $config = ['strategies' => [
        ['id' => 'hmac_sha256', 'config' => ['header' => 'x-sig', 'encoding' => 'hex', 'secretRef' => 'webhook_secret']],
        ['id' => 'plain_header', 'config' => ['header' => 'authorization', 'secretRef' => 'webhook_secret']],
    ]];

    expect(verifyWith('composite_any', $config, $body, ['authorization' => 'topsecret'])->valid)->toBeTrue()
        ->and(verifyWith('composite_any', $config, $body, ['x-sig' => $hex])->valid)->toBeTrue();

    $failed = verifyWith('composite_any', $config, $body, []);

    expect($failed->valid)->toBeFalse()
        ->and($failed->reason)->toContain('hmac_sha256')
        ->and($failed->reason)->toContain('plain_header');

    expect(verifyWith('composite_any', ['strategies' => []], $body, [])->valid)->toBeFalse();
});

test('none strategy is only valid in local/testing', function () {
    expect(verifyWith('none', [], '{}', [])->valid)->toBeTrue();

    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        $result = verifyWith('none', [], '{}', []);
        expect($result->valid)->toBeFalse()
            ->and($result->reason)->toBe('none_strategy_disabled_in_production');
    } finally {
        app()['env'] = $previous;
    }
});

test('hash_equals is used for secret comparisons (timing-safe, type-strict)', function () {
    // '0e123' == '0e456' is true with loose comparison; strict must fail.
    $config = ['header' => 'authorization', 'secretRef' => 'webhook_secret'];

    expect(verifyWith('plain_header', $config, '{}', ['authorization' => '0e456'], ['webhook_secret' => '0e123'])->valid)
        ->toBeFalse();
});

test('payload_app_id post-validation matches the payload app id against refs', function () {
    $platform = makeWebhookPlatform(['app_id' => 'app_123'], ['webhook_secret' => 's3']);

    $payload = ['app_id' => 'app_123'];

    expect(PostValidation::check(
        $platform, $payload, ['id' => 'payload_app_id', 'config' => ['jsonPath' => 'app_id', 'allowedRefs' => ['app_id']]]
    ))->toBeNull();

    expect(PostValidation::check(
        $platform, ['app_id' => 'other'], ['id' => 'payload_app_id', 'config' => ['jsonPath' => 'app_id', 'allowedRefs' => ['app_id']]]
    ))->not->toBeNull();

    expect(PostValidation::check(
        $platform, [], ['id' => 'payload_app_id', 'config' => ['jsonPath' => 'app_id', 'allowedRefs' => ['app_id']]]
    ))->toContain('missing');

    expect(PostValidation::check($platform, $payload, null))->toBeNull();
});

test('secret resolver reads secrets and platform row fields', function () {
    $platform = makeWebhookPlatform(['app_id' => 'app_9', 'client_id' => 'cli_9'], ['webhook_secret' => 'wh']);
    $platform->load('secrets');

    $resolver = SecretResolver::for($platform);

    expect($resolver('webhook_secret'))->toBe('wh')
        ->and($resolver('app_id'))->toBe('app_9')
        ->and($resolver('client_id'))->toBe('cli_9')
        ->and($resolver('nope'))->toBeNull();
});

test('driver defaults carry the specified verification and payload keys', function () {
    $salla = new SallaDriver;
    $zid = new ZidDriver;

    expect($salla->defaultWebhookVerification()['strategy'])->toBe('composite_any')
        ->and($zid->defaultWebhookVerification()['strategy'])->toBe('composite_any')
        ->and($salla->topic(['event' => 'order.created']))->toBe('order.created')
        ->and($salla->storeId(['merchant' => 'm1']))->toBe('m1')
        ->and($zid->storeId(['store_id' => 's1']))->toBe('s1')
        ->and($salla->externalEventId(['event' => 'x'], 'raw-body'))->toBe(hash('sha256', 'raw-body'))
        ->and($zid->externalEventId(['id' => 'evt-1'], 'raw-body'))->toBe('evt-1')
        ->and($salla->defaultConfig()['eventIdPayloadKeys'])->toBe([]);
});
