<?php

namespace Omarsenusi\MarketplacePlatforms\Drivers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Auth\PlatformApiClient;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Billing\ExternalSubscription;
use Omarsenusi\MarketplacePlatforms\Enums\AuthMode;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

class ZidDriver implements PlatformDriver
{
    use ExchangesOAuthCodes;
    use ExtractsPayloadFields;

    public function key(): string
    {
        return 'zid';
    }

    public function label(): string
    {
        return 'Zid';
    }

    public function authModes(): array
    {
        return [
            [
                'value' => AuthMode::OAuth,
                'label' => 'OAuth',
                'description' => 'Authorization-code callback flow via Zid OAuth.',
            ],
        ];
    }

    public function defaultAuthMode(): AuthMode
    {
        return AuthMode::OAuth;
    }

    public function defaultConfig(): array
    {
        return [
            'oauth' => [
                'authorizationUrl' => 'https://oauth.zid.sa/oauth/authorize',
                'tokenUrl' => 'https://oauth.zid.sa/oauth/token',
                // embedded_apps_tokens_write lets us register the
                // per-store embedded-launch token (managers/
                // embedded-apps-token). The partner dashboard must
                // grant it or the registration call 403s (non-fatal).
                'scopes' => ['embedded_apps_tokens_write'],
                'redirectPath' => '/platforms/zid/callback',
            ],
            'api' => [
                'baseUrl' => 'https://api.zid.sa/v1',
                'profilePath' => '/managers/account/profile',
            ],
            'tokens' => [
                'model' => 'dual',
                'fields' => ['access_token', 'authorization', 'refresh_token'],
                'headerMapping' => [
                    'Authorization' => 'Bearer {authorization}',
                    'X-Manager-Token' => '{access_token}',
                ],
                // Refresh this many hours BEFORE the access token expires
                // (Zid tokens live ~1 year). Overridable per platform.
                'refreshLeadHours' => 720,
            ],
            'storeIdPayloadKeys' => ['store_id'],
            'eventNamePayloadKeys' => ['event'],
            'eventIdPayloadKeys' => ['id'],
        ];
    }

    public function defaultWebhookVerification(): array
    {
        return [
            'strategy' => 'composite_any',
            'config' => [
                'strategies' => [
                    [
                        'id' => 'static_header',
                        'config' => [
                            'header' => 'x-zid-api-key',
                            'expectedRef' => 'partner_api_key',
                        ],
                    ],
                    [
                        'id' => 'basic_auth_password',
                        'config' => [
                            'secretRef' => 'webhook_secret',
                        ],
                    ],
                    [
                        'id' => 'hmac_sha256',
                        'config' => [
                            'header' => 'x-zid-signature',
                            'encoding' => 'hex',
                            'secretRef' => 'webhook_secret',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function requiresSecrets(AuthMode $mode): array
    {
        return ['client_secret'];
    }

    /**
     * Zid profile: GET {api.baseUrl}/managers/account/profile (user:
     * name, email, mobile/phone, store{id, uuid, title|name, url,
     * currency…}) and, if the store id is missing,
     * {api.baseUrl}/managers/account/store. Read defensively.
     * externalStoreId = numeric store id as string.
     */
    public function fetchStoreProfile(PlatformConnection $connection): StoreProfile
    {
        $connection->loadMissing('platform');
        $config = is_array($connection->platform->config) ? $connection->platform->config : [];

        $profilePath = (string) (
            $config['api']['profilePath']
            ?? $this->defaultConfig()['api']['profilePath']
            ?? '/managers/account/profile'
        );

        $response = PlatformApiClient::for($connection)->get($profilePath);

        if (! $response->successful()) {
            throw new PermanentWebhookException(
                "Zid profile fetch failed (http {$response->status()})."
            );
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $store = is_array($payload['store'] ?? null) ? $payload['store'] : (is_array($payload['manager_store'] ?? null) ? $payload['manager_store'] : []);

        if (! isset($store['id']) && $connection->external_store_id === '') {
            $storeResponse = PlatformApiClient::for($connection)->get('/managers/account/store');
            if ($storeResponse->successful()) {
                $storePayload = $storeResponse->json();
                $storePayload = is_array($storePayload) ? $storePayload : [];
                $store = is_array($storePayload['store'] ?? null) ? $storePayload['store'] : $storePayload;
            }
        }

        $storeId = $store['id'] ?? $store['store_id'] ?? null;
        $storeId = is_numeric($storeId) || (is_string($storeId) && trim($storeId) !== '')
            ? (string) $storeId
            : (string) ($connection->external_store_id ?? '');

        $preferredLocale = is_string($user['locale'] ?? null) ? $user['locale'] : null;

        $phone = $user['mobile'] ?? $user['phone'] ?? null;
        $phone = is_string($phone) ? $phone : (is_numeric($phone) ? (string) $phone : null);

        return new StoreProfile(
            externalStoreId: $storeId,
            storeName: StoreProfile::localized($store['title'] ?? $store['name'] ?? null, $preferredLocale),
            storeDomain: is_string($store['domain'] ?? null) ? $store['domain'] : null,
            storeUrl: is_string($store['url'] ?? null) ? $store['url'] : null,
            ownerName: is_string($user['name'] ?? null) ? $user['name'] : null,
            ownerEmail: is_string($user['email'] ?? null) ? $user['email'] : null,
            ownerPhone: $phone,
            currency: is_string($store['currency'] ?? null) ? $store['currency'] : (is_string($store['store_currency'] ?? null) ? $store['store_currency'] : null),
            locale: $preferredLocale,
            metadata: [
                'store_uuid' => $store['uuid'] ?? null,
                // Best effort: stored as the store avatar.
                'logo' => is_string($store['logo'] ?? null) ? $store['logo'] : null,
            ],
        );
    }

    /**
     * Zid subscription webhooks are flat:
     * {app_id, store_id, store_url, plan_name, plan_id, plan_type,
     * status, start_date, end_date, amount_paid, event_name,
     * payment_date, merchant_email, ...}.
     *
     * `warning` carries no state change — it returns an `ignored`
     * event so handlers store it for visibility without
     * touching the workspace plan.
     */
    public function parseSubscriptionEvent(array $payload): ?ExternalSubscription
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : null;

        if ($event === null) {
            $event = is_string($payload['event_name'] ?? null) ? $payload['event_name'] : null;
        }

        if ($event === null) {
            return null;
        }

        $state = match ($event) {
            'app.market.subscription.active', 'app.market.subscription.renew', 'app.market.subscription.upgrade' => (
                ($payload['plan_type'] ?? null) === 'Free Trial' ? 'trialing' : 'active'
            ),
            'app.market.subscription.warning' => 'ignored',
            'app.market.subscription.suspended', 'app.market.subscription.expired', 'app.market.subscription.refunded' => 'lapsed',
            default => null,
        };

        if ($state === null) {
            return null;
        }

        $planId = $payload['plan_id'] ?? null;
        $key = is_numeric($planId) || (is_string($planId) && trim($planId) !== '')
            ? (string) $planId
            : '';

        if ($key === '') {
            return null;
        }

        $storeId = $payload['store_id'] ?? null;
        $storeId = is_numeric($storeId) || (is_string($storeId) && trim($storeId) !== '')
            ? (string) $storeId
            : null;

        $amount = isset($payload['amount_paid']) && is_numeric($payload['amount_paid'])
            ? (float) $payload['amount_paid']
            : null;

        $planName = is_string($payload['plan_name'] ?? null) && trim($payload['plan_name']) !== ''
            ? $payload['plan_name']
            : null;

        return new ExternalSubscription(
            driver: 'zid',
            externalPlanKey: $key,
            planName: $planName,
            planType: is_string($payload['plan_type'] ?? null) ? $payload['plan_type'] : null,
            state: $state,
            startsAt: $this->parseZidDate($payload['start_date'] ?? null),
            endsAt: $this->parseZidDate($payload['end_date'] ?? null),
            externalSubscriptionId: $storeId !== null ? "zid:{$storeId}" : null,
            amount: $amount,
            eventName: $event,
            occurredAt: $this->parseZidDate($payload['payment_date'] ?? null)
                ?? $this->parseZidDate($payload['start_date'] ?? null)
                ?? CarbonImmutable::now(),
            raw: [
                'plan_name' => $planName,
                'plan_type' => is_string($payload['plan_type'] ?? null) ? $payload['plan_type'] : null,
                'store_url' => is_string($payload['store_url'] ?? null) ? $payload['store_url'] : null,
                'status' => is_string($payload['status'] ?? null) ? $payload['status'] : null,
            ],
        );
    }

    /**
     * Zid embedded launch: the iframe URL carries ?token=<uuid> that
     * we registered per store (RegisterZidEmbeddedToken). The token
     * itself is never stored — only its sha256.
     */
    public function verifyEmbeddedToken(Platform $platform, string $token): ?string
    {
        if (trim($token) === '') {
            return null;
        }

        $connection = PlatformConnection::query()
            ->where('platform_id', $platform->id)
            ->where('embedded_token_hash', hash('sha256', $token))
            ->first();

        if ($connection === null) {
            Log::warning('platforms.embedded.zid_token_unknown', [
                'platform_id' => $platform->id,
            ]);

            return null;
        }

        return $connection->external_store_id;
    }

    private function parseZidDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
