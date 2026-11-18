<?php

namespace Omarsenusi\MarketplacePlatforms\Drivers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Billing\ExternalSubscription;
use Omarsenusi\MarketplacePlatforms\Enums\AuthMode;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;

class SallaDriver implements PlatformDriver
{
    use ExchangesOAuthCodes;
    use ExtractsPayloadFields;

    public function key(): string
    {
        return 'salla';
    }

    public function label(): string
    {
        return 'Salla';
    }

    public function authModes(): array
    {
        return [
            [
                'value' => AuthMode::Easy,
                'label' => 'Easy mode',
                'description' => 'Tokens are delivered via the app.store.authorize webhook, no OAuth callback needed — required for published apps.',
            ],
            [
                'value' => AuthMode::Custom,
                'label' => 'Custom mode',
                'description' => 'Authorization-code callback flow, for development and testing.',
            ],
        ];
    }

    public function defaultAuthMode(): AuthMode
    {
        return AuthMode::Easy;
    }

    public function defaultConfig(): array
    {
        return [
            'oauth' => [
                'authorizationUrl' => 'https://accounts.salla.sa/oauth2/auth',
                'tokenUrl' => 'https://accounts.salla.sa/oauth2/token',
                'userInfoUrl' => 'https://accounts.salla.sa/oauth2/user/info',
                'scopes' => ['offline_access'],
                'redirectPath' => '/platforms/salla/callback',
            ],
            'api' => [
                'baseUrl' => 'https://api.salla.dev/admin/v2',
            ],
            'embedded' => [
                'introspectUrl' => 'https://api.salla.dev/exchange-authority/v1/introspect',
            ],
            'tokens' => [
                'model' => 'simple',
                'fields' => ['access_token', 'refresh_token'],
                // Refresh this many hours BEFORE the access token expires
                // (Salla access tokens live 14 days). Overridable per platform.
                'refreshLeadHours' => 72,
            ],
            'storeIdPayloadKeys' => ['merchant'],
            'eventNamePayloadKeys' => ['event'],
            // Salla sends no event id; identical retries carry identical
            // bodies, so externalEventId() falls back to sha256(rawBody).
            'eventIdPayloadKeys' => [],
        ];
    }

    public function defaultWebhookVerification(): array
    {
        // A Salla app may be configured with strategy "signature" or
        // "token"; both use the same webhook_secret, so accept either.
        return [
            'strategy' => 'composite_any',
            'config' => [
                'strategies' => [
                    [
                        'id' => 'hmac_sha256',
                        'config' => [
                            'header' => 'x-salla-signature',
                            'encoding' => 'hex',
                            'secretRef' => 'webhook_secret',
                        ],
                    ],
                    [
                        'id' => 'plain_header',
                        'config' => [
                            'header' => 'authorization',
                            'secretRef' => 'webhook_secret',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function requiresSecrets(AuthMode $mode): array
    {
        return match ($mode) {
            AuthMode::Easy => ['client_secret', 'webhook_secret'],
            default => ['client_secret'],
        };
    }

    /**
     * Salla profile: GET {oauth.userInfoUrl} with the access token.
     * Shape: data.{name,email,mobile, merchant:{id,name,username,domain,currency?}}.
     */
    public function fetchStoreProfile(PlatformConnection $connection): StoreProfile
    {
        $connection->loadMissing('platform');
        $config = is_array($connection->platform->config) ? $connection->platform->config : [];
        $oauth = array_merge(
            is_array($this->defaultConfig()['oauth'] ?? null) ? $this->defaultConfig()['oauth'] : [],
            is_array($config['oauth'] ?? null) ? $config['oauth'] : [],
        );
        $url = (string) ($oauth['userInfoUrl'] ?? '');

        $tokens = is_array($connection->tokens) ? $connection->tokens : [];
        $accessToken = (string) ($tokens['access_token'] ?? '');

        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(15)
            ->get($url);

        if (! $response->successful()) {
            throw new PermanentWebhookException(
                "Salla profile fetch failed (http {$response->status()})."
            );
        }

        $payload = $response->json();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $merchant = is_array($data['merchant'] ?? null) ? $data['merchant'] : [];

        $storeId = $merchant['id'] ?? $data['merchant_id'] ?? $data['id'] ?? null;
        $storeId = is_numeric($storeId) || (is_string($storeId) && trim($storeId) !== '')
            ? (string) $storeId
            : (string) ($connection->external_store_id ?? '');

        return new StoreProfile(
            externalStoreId: $storeId,
            storeName: StoreProfile::localized($merchant['name'] ?? null) ?? StoreProfile::localized($data['store_name'] ?? null),
            storeDomain: is_string($merchant['domain'] ?? null) ? $merchant['domain'] : null,
            storeUrl: $this->sallaStoreUrl($merchant),
            ownerName: is_string($data['name'] ?? null) ? $data['name'] : null,
            ownerEmail: is_string($data['email'] ?? null) ? $data['email'] : null,
            ownerPhone: is_string($data['mobile'] ?? null) ? $data['mobile'] : (is_string($data['phone'] ?? null) ? $data['phone'] : null),
            currency: is_string($merchant['currency'] ?? null) ? $merchant['currency'] : null,
            locale: is_string($data['locale'] ?? null) ? $data['locale'] : null,
            metadata: ['merchant' => $merchant],
        );
    }

    private function sallaStoreUrl(array $merchant): ?string
    {
        $domain = $merchant['domain'] ?? $merchant['username'] ?? null;

        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        return str_starts_with($domain, 'http') ? $domain : 'https://'.$domain;
    }

    /**
     * Salla subscription webhooks. Payload shape:
     * {event, merchant, created_at, data:{subscription_id, item_type,
     * plan_name, plan_type, plan_period, start_date, end_date,
     * renew_date, price, total, store_type, ...}}.
     *
     * Only item_type "plan" carries plan state — anything else
     * (addons, …) returns null so the handler logs and skips.
     */
    public function parseSubscriptionEvent(array $payload): ?ExternalSubscription
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : null;

        if ($event === null) {
            return null;
        }

        $state = match ($event) {
            'app.subscription.started', 'app.subscription.renewed' => 'active',
            'app.trial.started' => 'trialing',
            'app.subscription.expired', 'app.subscription.canceled',
            'app.trial.expired', 'app.trial.canceled' => 'lapsed',
            default => null,
        };

        if ($state === null) {
            return null;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        // Live Salla payloads omit `item_type` for plan subscriptions
        // (only addon events carry item_type "addon" / an item_slug).
        // Treat a missing item_type as a plan; skip only real addons.
        $itemType = $data['item_type'] ?? null;

        if (($itemType !== null && $itemType !== 'plan')
            || (is_string($data['item_slug'] ?? null) && $data['item_slug'] !== '')) {
            return null;
        }

        $key = trim((string) ($data['plan_name'] ?? ''));

        if ($key === '') {
            return null;
        }

        // NOTE: data.id in live payloads is the APP id (same as in
        // app.store.authorize), not a subscription id — never use it.
        $externalId = isset($data['subscription_id']) && is_scalar($data['subscription_id']) && (string) $data['subscription_id'] !== ''
            ? (string) $data['subscription_id']
            : null;

        $endsAt = $state === 'trialing'
            ? $this->parseSallaDate($data['end_date'] ?? null)
            : ($this->parseSallaDate($data['renew_date'] ?? null) ?? $this->parseSallaDate($data['end_date'] ?? null));

        $amount = isset($data['total']) && is_numeric($data['total'])
            ? (float) $data['total']
            : (isset($data['price']) && is_numeric($data['price']) ? (float) $data['price'] : null);

        return new ExternalSubscription(
            driver: 'salla',
            externalPlanKey: $key,
            planName: $key,
            planType: is_string($data['plan_type'] ?? null) ? $data['plan_type'] : null,
            state: $state,
            startsAt: $this->parseSallaDate($data['start_date'] ?? null),
            endsAt: $endsAt,
            externalSubscriptionId: $externalId,
            amount: $amount,
            eventName: $event,
            occurredAt: $this->parseSallaTimestamp(
                $payload['created_at'] ?? $data['subscription_at'] ?? $data['created_at'] ?? null,
            ),
            raw: [
                'plan_name' => $key,
                'plan_type' => is_string($data['plan_type'] ?? null) ? $data['plan_type'] : null,
                'plan_period' => is_string($data['plan_period'] ?? null) ? $data['plan_period'] : null,
                'store_type' => is_string($data['store_type'] ?? null) ? $data['store_type'] : null,
            ],
        );
    }

    /**
     * Verify a short-lived embedded-page token
     * (https://api.salla.dev/exchange-authority/v1/introspect).
     * Returns the merchant (store) id on success, null otherwise.
     * Never logs the token.
     */
    public function verifyEmbeddedToken(Platform $platform, string $token): ?string
    {
        $appId = is_string($platform->app_id) ? trim($platform->app_id) : '';

        if ($appId === '') {
            Log::warning('platforms.embedded.salla_missing_app_id', [
                'platform_id' => $platform->id,
            ]);

            return null;
        }

        $config = is_array($platform->config) ? $platform->config : [];
        $url = (string) (
            $config['embedded']['introspectUrl']
            ?? $this->defaultConfig()['embedded']['introspectUrl']
        );

        try {
            $response = Http::acceptJson()->asJson()
                ->withHeaders(['S-Source' => $appId])
                ->timeout(10)
                ->post($url, ['token' => $token]);
        } catch (\Throwable $e) {
            Log::warning('platforms.embedded.salla_introspect_failed', [
                'platform_id' => $platform->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('platforms.embedded.salla_introspect_rejected', [
                'platform_id' => $platform->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $json = $response->json();

        if (! is_array($json) || ($json['success'] ?? null) !== true) {
            return null;
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $merchantId = $data['merchant_id'] ?? null;

        if (! is_numeric($merchantId) && ! (is_string($merchantId) && trim($merchantId) !== '')) {
            return null;
        }

        // data.exp is an ISO-8601 string. A proof without a parseable,
        // future expiry is not a proof.
        $exp = $data['exp'] ?? null;

        if (! is_string($exp) || trim($exp) === '') {
            return null;
        }

        try {
            if (CarbonImmutable::parse($exp)->isPast()) {
                Log::warning('platforms.embedded.salla_token_expired', [
                    'platform_id' => $platform->id,
                ]);

                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return (string) $merchantId;
    }

    private function parseSallaDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), 'Asia/Riyadh');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseSallaTimestamp(mixed $value): CarbonImmutable
    {
        // Top-level created_at is "Y-m-d H:i:s" in Asia/Riyadh.
        if (is_string($value) && trim($value) !== '') {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d H:i:s', trim($value), 'Asia/Riyadh')
                    ?: CarbonImmutable::now('Asia/Riyadh');
            } catch (\Throwable) {
                // Fall through to now().
            }
        }

        return CarbonImmutable::now('Asia/Riyadh');
    }
}
