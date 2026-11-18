<?php

namespace Omarsenusi\MarketplacePlatforms\Support;

use Illuminate\Database\Eloquent\Builder;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookDelivery;

/**
 * Free-text store search for the delivery monitor: store id, owner
 * email, store name, or store link/domain. Matches through the
 * delivery's connection AND through the store id in the payload, so
 * deliveries that arrived before the connection existed are found too.
 */
final class DeliverySearch
{
    /**
     * @param  Builder<PlatformWebhookDelivery>  $query
     */
    public static function apply(Builder $query, string $term, ?string $platformId = null): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        // A pasted link matches on host/path, whatever the scheme.
        $needle = mb_strtolower((string) preg_replace('#^https?://(www\.)?#i', '', rtrim($term, '/')));
        // No LIKE escaping: escape characters differ per driver (sqlite
        // has none by default), and a stray `_`/`%` wildcard in a term
        // only widens a search — it can't leak or break anything.
        $like = '%'.$needle.'%';

        $connections = PlatformConnection::query()
            ->when($platformId !== null, fn ($q) => $q->where('platform_id', $platformId))
            ->where(function ($q) use ($term, $like): void {
                $q->where('external_store_id', $term)
                    ->orWhereHas('storeSettings', function ($s) use ($like): void {
                        $s->whereRaw('LOWER(owner_email) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(store_url) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(store_domain) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(store_name) LIKE ?', [$like]);
                    });
            })
            ->limit(200)
            ->get(['id', 'external_store_id']);

        $connectionIds = $connections->pluck('id')->all();
        $storeIds = array_values(array_unique(array_merge(
            [$term],
            $connections->pluck('external_store_id')->map(fn ($id) => (string) $id)->all(),
        )));

        $query->where(function ($q) use ($connectionIds, $storeIds): void {
            if ($connectionIds !== []) {
                $q->whereIn('connection_id', $connectionIds);
            }

            foreach ($storeIds as $storeId) {
                foreach (['merchant', 'store_id'] as $key) {
                    $q->orWhere("payload->{$key}", $storeId);

                    // Salla sends `merchant` as a JSON number.
                    if (ctype_digit($storeId) && strlen($storeId) <= 18) {
                        $q->orWhere("payload->{$key}", (int) $storeId);
                    }
                }
            }
        });
    }
}
