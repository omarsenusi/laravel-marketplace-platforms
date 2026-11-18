<?php

namespace Omarsenusi\MarketplacePlatforms\Drivers;

use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Auth\TokenSet;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Support\PublicUrl;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;

/**
 * Shared OAuth plumbing for Salla + Zid. Drivers only declare their
 * per-endpoint parsing (TokenSet::fromSallaResponse / fromZidResponse
 * + profile normalization).
 */
trait ExchangesOAuthCodes
{
    /**
     * @param  array<string, mixed>  $defaults  Driver defaultConfig().
     * @param  array<string, mixed>  $config  Platform row config override.
     * @return array<string, mixed>
     */
    private function oauthConfig(array $defaults, array $config): array
    {
        $base = is_array($defaults['oauth'] ?? null) ? $defaults['oauth'] : [];
        $override = is_array($config['oauth'] ?? null) ? $config['oauth'] : [];

        return array_merge($base, $override);
    }

    private function tokenUrl(Platform $platform, array $defaults): string
    {
        $config = is_array($platform->config) ? $platform->config : [];
        $oauth = $this->oauthConfig($defaults, $config);

        return (string) ($oauth['tokenUrl'] ?? '');
    }

    /**
     * @return array{url: string, clientId: ?string, clientSecret: ?string, redirectUri: string}
     */
    private function tokenRequestBase(Platform $platform, array $defaults): array
    {
        $config = is_array($platform->config) ? $platform->config : [];
        $oauth = $this->oauthConfig($defaults, $config);
        $platform->loadMissing('secrets');

        $redirectPath = (string) ($oauth['redirectPath'] ?? '');

        return [
            'url' => $this->tokenUrl($platform, $defaults),
            'clientId' => $platform->client_id,
            'clientSecret' => $platform->secrets?->client_secret,
            'redirectUri' => $redirectPath !== '' ? PublicUrl::to($redirectPath) : '',
        ];
    }

    public function exchangeCode(Platform $platform, string $code): TokenSet
    {
        $base = $this->tokenRequestBase($platform, $this->defaultConfig());

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->post($base['url'], array_filter([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $base['clientId'],
                'client_secret' => $base['clientSecret'],
                'redirect_uri' => $base['redirectUri'] !== '' ? $base['redirectUri'] : null,
            ]));

        if (! $response->successful()) {
            throw new PermanentWebhookException(
                "Token exchange failed for platform [{$platform->slug}] (http {$response->status()})."
            );
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];

        return $this->key() === 'zid'
            ? TokenSet::fromZidResponse($data)
            : TokenSet::fromSallaResponse($data);
    }

    public function refresh(PlatformConnection $connection): TokenSet
    {
        $connection->loadMissing('platform');
        $platform = $connection->platform;

        $tokens = is_array($connection->tokens) ? $connection->tokens : [];
        $refreshToken = $tokens['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new PermanentWebhookException(
                "No refresh token stored for connection [{$connection->id}]."
            );
        }

        $base = $this->tokenRequestBase($platform, $this->defaultConfig());

        $payload = array_filter([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $base['clientId'],
            'client_secret' => $base['clientSecret'],
            // Zid requires redirect_uri on refresh; harmless elsewhere.
            'redirect_uri' => $base['redirectUri'] !== '' ? $base['redirectUri'] : null,
        ]);

        $response = Http::asForm()->acceptJson()->timeout(15)->post($base['url'], $payload);

        if ($response->status() === 401 || $this->isInvalidGrant($response->json())) {
            throw new PermanentWebhookException(
                "Refresh rejected (invalid_grant) for connection [{$connection->id}]."
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Token refresh failed for connection [{$connection->id}] (http {$response->status()})."
            );
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];

        return $this->key() === 'zid'
            ? TokenSet::fromZidResponse($data)
            : TokenSet::fromSallaResponse($data);
    }

    private function isInvalidGrant(mixed $data): bool
    {
        if (! is_array($data)) {
            return false;
        }

        $haystack = strtolower((string) json_encode($data));

        return str_contains($haystack, 'invalid_grant');
    }
}
