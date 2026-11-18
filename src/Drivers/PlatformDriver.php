<?php

namespace Omarsenusi\MarketplacePlatforms\Drivers;

use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Auth\TokenSet;
use Omarsenusi\MarketplacePlatforms\Billing\ExternalSubscription;
use Omarsenusi\MarketplacePlatforms\Enums\AuthMode;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

interface PlatformDriver
{
    public function key(): string;

    public function label(): string;

    /**
     * List of supported auth modes with UI metadata.
     *
     * @return list<array{value: AuthMode, label: string, description: string}>
     */
    public function authModes(): array;

    public function defaultAuthMode(): AuthMode;

    /**
     * Non-secret behaviour config: oauth endpoints, api base, token
     * model, and payload key hints for webhook ingestion (later phases).
     *
     * @return array<string, mixed>
     */
    public function defaultConfig(): array;

    /**
     * Config-driven payload extraction. $config is the platform's stored
     * config (platform.config merged over driver defaults by the caller);
     * key lists are dot paths, first non-empty wins.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    public function storeId(array $payload, array $config = []): ?string;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    public function topic(array $payload, array $config = []): ?string;

    /**
     * First non-empty eventIdPayloadKeys entry, else sha256($rawBody)
     * (dedupes identical retries carrying identical bodies).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    public function externalEventId(array $payload, string $rawBody, array $config = []): string;

    /**
     * @return array{strategy: string, config: array<string, mixed>}
     */
    public function defaultWebhookVerification(): array;

    /**
     * @return list<string> required secret field names on platform_secrets
     */
    public function requiresSecrets(AuthMode $mode): array;

    /**
     * Authorization-code exchange against the driver's token endpoint.
     * Never throws for transport-level details leakage — callers wrap
     * failures into friendly error pages (never log tokens).
     */
    public function exchangeCode(Platform $platform, string $code): TokenSet;

    /**
     * Refresh-token grant against the driver's token endpoint.
     *
     * @throws Webhooks\PermanentWebhookException on invalid_grant / 401 (no retry)
     */
    public function refresh(PlatformConnection $connection): TokenSet;

    /**
     * Fetch + normalize the store/owner profile for an authorized
     * connection. Read defensively: keys may be missing or localized.
     */
    public function fetchStoreProfile(PlatformConnection $connection): StoreProfile;

    /**
     * Parse a marketplace subscription/trial webhook payload into a
     * normalized event. Returns null when the payload carries no
     * subscription state (e.g. addons, unknown events) — callers log
     * and skip.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseSubscriptionEvent(array $payload): ?ExternalSubscription;

    /**
     * Verify a short-lived embedded-launch token and return the
     * merchant's external store id, or null when unverifiable.
     */
    public function verifyEmbeddedToken(Platform $platform, string $token): ?string;
}
