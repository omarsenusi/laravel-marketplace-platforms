<?php

namespace Omarsenusi\MarketplacePlatforms\Support;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\PlatformStoreSettings;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;

/**
 * Store card data (name, logo, link, owner) for webhook deliveries.
 * Never includes tokens. Resolves the store through the
 * delivery's connection, falling back to (platform, store id from the
 * payload) for deliveries that arrived before the connection existed
 * — in one query per page, not per row.
 *
 * @phpstan-type Summary array{
 *     connection_id: string,
 *     external_store_id: string,
 *     status: string,
 *     name: string|null,
 *     logo_url: string|null,
 *     url: string|null,
 *     domain: string|null,
 *     owner_name: string|null,
 *     owner_email: string|null,
 *     owner_phone: string|null,
 *     currency: string|null,
 * }
 */
final class StoreSummary
{
    /**
     * @param  iterable<PlatformWebhookDelivery>  $deliveries
     * @return array<string, array<string, mixed>|null> keyed by delivery id
     */
    public static function forDeliveries(iterable $deliveries): array
    {
        $result = [];
        $missing = [];

        foreach ($deliveries as $delivery) {
            $connection = $delivery->connection_id !== null ? $delivery->connection : null;

            if ($connection !== null) {
                $result[$delivery->id] = self::fromConnection($connection);

                continue;
            }

            $storeId = self::payloadStoreId($delivery);
            $result[$delivery->id] = null;

            if ($storeId !== null) {
                $missing[$delivery->id] = [$delivery->platform_id, $storeId];
            }
        }

        if ($missing === []) {
            return $result;
        }

        $connections = PlatformConnection::query()
            ->with('storeSettings')
            ->whereIn('platform_id', array_unique(array_column($missing, 0)))
            ->whereIn('external_store_id', array_unique(array_column($missing, 1)))
            ->get()
            ->keyBy(fn (PlatformConnection $c): string => $c->platform_id.'|'.$c->external_store_id);

        foreach ($missing as $deliveryId => [$platformId, $storeId]) {
            $connection = $connections->get($platformId.'|'.$storeId);
            $result[$deliveryId] = $connection !== null ? self::fromConnection($connection) : null;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromConnection(PlatformConnection $connection): array
    {
        $settings = $connection->storeSettings;

        return [
            'connection_id' => $connection->id,
            'external_store_id' => $connection->external_store_id,
            'status' => $connection->status->value,
            'name' => $settings?->store_name,
            'logo_url' => self::logoUrl($settings),
            'url' => self::httpUrl($settings?->store_url) ?? self::httpUrl($settings?->store_domain),
            'domain' => $settings?->store_domain,
            'owner_name' => $settings?->owner_name,
            'owner_email' => $settings?->owner_email,
            'owner_phone' => $settings?->owner_phone,
            'currency' => $settings?->currency,
        ];
    }

    public static function payloadStoreId(PlatformWebhookDelivery $delivery): ?string
    {
        $payload = is_array($delivery->payload) ? $delivery->payload : [];
        $storeId = $payload['merchant'] ?? $payload['store_id'] ?? null;

        return is_string($storeId) || is_int($storeId) ? (string) $storeId : null;
    }

    /**
     * Salla: user-info merchant.avatar. Zid: store logo (best effort).
     */
    private static function logoUrl(?PlatformStoreSettings $settings): ?string
    {
        $metadata = is_array($settings?->metadata) ? $settings->metadata : [];

        foreach ([
            $metadata['merchant']['avatar'] ?? null,
            $metadata['logo'] ?? null,
            $metadata['store_logo'] ?? null,
        ] as $candidate) {
            $url = self::httpUrl(is_string($candidate) ? $candidate : null);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Only real http(s) URLs reach host displays (no javascript:/data:).
     */
    private static function httpUrl(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.ltrim($value, '/');
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }
}
