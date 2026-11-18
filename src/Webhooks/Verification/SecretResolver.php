<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification;

use Omarsenusi\MarketplacePlatforms\Models\Platform;

/**
 * Builds the secret resolver callable used by VerificationContext:
 * secret refs resolve from the platform's PlatformSecret row, app_id /
 * client_id resolve from the platform row itself.
 */
final class SecretResolver
{
    /**
     * @return \Closure(string): ?string
     */
    public static function for(Platform $platform): \Closure
    {
        $secrets = $platform->relationLoaded('secrets') ? $platform->secrets : $platform->secrets()->first();

        return static function (string $ref) use ($platform, $secrets): ?string {
            return match ($ref) {
                'client_secret' => $secrets?->client_secret,
                'webhook_secret' => $secrets?->webhook_secret,
                'partner_api_key' => $secrets?->partner_api_key,
                'app_id' => $platform->app_id,
                'client_id' => $platform->client_id,
                default => null,
            };
        };
    }
}
