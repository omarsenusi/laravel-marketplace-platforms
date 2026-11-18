<?php

namespace Omarsenusi\MarketplacePlatforms\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Normalized token pair as returned by an OAuth exchange/refresh.
 *
 * - Salla (model simple): access_token + refresh_token (+ scope).
 * - Zid (model dual): the token response's `access_token` is the
 *   manager token (sent as X-Manager-Token) and its `authorization`
 *   field is the bearer token (sent as "Authorization: Bearer …").
 *   Normalized here so the rest of the package only speaks
 *   manager/authorization naming for Zid and access/refresh for Salla.
 */
final class TokenSet
{
    public function __construct(
        public readonly ?string $accessToken = null,
        public readonly ?string $refreshToken = null,
        public readonly ?string $managerToken = null,
        public readonly ?string $authorizationToken = null,
        public readonly ?string $scope = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?int $expiresIn = null,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $response  Decoded token endpoint body.
     */
    public static function fromSallaResponse(array $response): self
    {
        $expiresAt = null;
        $expiresIn = null;

        if (isset($response['expires']) && is_numeric($response['expires'])) {
            // Salla sends an ABSOLUTE unix timestamp (seconds), not a
            // duration. Cast through int (never add it to now()).
            $expiresAt = CarbonImmutable::createFromTimestamp((int) $response['expires']);
        } elseif (isset($response['expires_in']) && is_numeric($response['expires_in'])) {
            $expiresIn = (int) $response['expires_in'];
            $expiresAt = Carbon::now()->addSeconds($expiresIn)->toImmutable();
        }

        $scope = $response['scope'] ?? null;

        return new self(
            accessToken: isset($response['access_token']) ? (string) $response['access_token'] : null,
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            scope: is_string($scope) ? $scope : (is_array($scope) ? implode(' ', $scope) : null),
            expiresAt: $expiresAt,
            expiresIn: $expiresIn,
            raw: [],
        );
    }

    /**
     * @param  array<string, mixed>  $response  Decoded token endpoint body.
     */
    public static function fromZidResponse(array $response): self
    {
        // Zid mapping (storage boundary — see TokenStore):
        //   response `access_token`  → manager_token   (X-Manager-Token)
        //   response `authorization` → authorization_token (Bearer …)
        $expiresIn = isset($response['expires_in']) && is_numeric($response['expires_in'])
            ? (int) $response['expires_in']
            : 31_536_000;

        return new self(
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            managerToken: isset($response['access_token']) ? (string) $response['access_token'] : null,
            authorizationToken: isset($response['authorization']) ? (string) $response['authorization'] : null,
            expiresAt: Carbon::now()->addSeconds($expiresIn)->toImmutable(),
            expiresIn: $expiresIn,
            raw: [],
        );
    }

    public function hasRefreshToken(): bool
    {
        return is_string($this->refreshToken) && $this->refreshToken !== '';
    }

    /**
     * Shape TokenStore::write() expects, per driver token model.
     *
     * @return array<string, string>
     */
    public function toStorageArray(string $driverKey): array
    {
        $shape = $driverKey === 'zid'
            ? [
                'manager_token' => $this->managerToken,
                'authorization_token' => $this->authorizationToken,
                'refresh_token' => $this->refreshToken,
            ]
            : [
                'access_token' => $this->accessToken,
                'refresh_token' => $this->refreshToken,
                'scope' => $this->scope,
            ];

        return array_filter($shape, static fn ($v) => is_string($v) && $v !== '');
    }
}
