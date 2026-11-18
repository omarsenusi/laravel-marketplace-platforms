<?php

namespace Omarsenusi\MarketplacePlatforms\Catalog;

use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Throwable;

/**
 * Zid Merchant API catalogue reader (base https://api.zid.sa/v1).
 *
 * GET /products/?page=N&page_size=50[&search=…] → {results:[…], next:…}.
 * GET /products/{id}/ → product object (possibly wrapped). Every call
 * carries the extra manager headers (Store-Id, Role, Access-Token).
 */
final class ZidCatalogReader implements CatalogReader
{
    use RemembersDeferral;

    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function page(PlatformConnection $connection, int $page, string $lane): CatalogPage
    {
        try {
            $result = $this->gateway->get(
                $connection, '/products/',
                ['page' => max(1, $page), 'page_size' => 50],
                $lane, 0, $this->headers($connection),
            );
        } catch (Throwable) {
            return CatalogPage::failed('transport_error');
        }

        if ($result->deferred) {
            return CatalogPage::deferred($result->retryAfter);
        }

        if (! $result->ok || ! is_array($result->json)) {
            return CatalogPage::failed($result->error);
        }

        return CatalogPage::ok(
            $this->products($result->json['results'] ?? null),
            $this->nextPage($result->json, max(1, $page)),
        );
    }

    public function find(PlatformConnection $connection, string $id, string $lane): ?StoreProduct
    {
        $this->markResolved();

        if (trim($id) === '') {
            return null;
        }

        try {
            $result = $this->gateway->get(
                $connection, '/products/'.rawurlencode(trim($id)).'/',
                [], $lane, $lane === 'realtime' ? 60 : 0, $this->headers($connection),
            );
        } catch (Throwable) {
            return null;
        }

        if ($result->deferred) {
            $this->markDeferred($result->retryAfter);

            return null;
        }

        if (! $result->ok || ! is_array($result->json)) {
            return null;
        }

        $data = $this->unwrap($result->json);

        if ($data === null) {
            return null;
        }

        try {
            return StoreProduct::fromZid($data);
        } catch (Throwable) {
            return null;
        }
    }

    public function search(PlatformConnection $connection, string $query, int $limit, string $lane): CatalogPage
    {
        $this->markResolved();

        $query = trim($query);

        if ($query === '') {
            return CatalogPage::ok([]);
        }

        $limit = max(1, min(8, $limit));

        try {
            $result = $this->gateway->get(
                $connection, '/products/',
                ['page' => 1, 'page_size' => 50, 'search' => $query],
                $lane, $lane === 'realtime' ? 60 : 0, $this->headers($connection),
            );
        } catch (Throwable) {
            return CatalogPage::failed('transport_error');
        }

        if ($result->deferred) {
            $this->markDeferred($result->retryAfter);

            return CatalogPage::deferred($result->retryAfter);
        }

        if (! $result->ok || ! is_array($result->json)) {
            return CatalogPage::failed($result->error);
        }

        return CatalogPage::ok(array_slice($this->products($result->json['results'] ?? null), 0, $limit));
    }

    /**
     * @return array<string, string>
     */
    private function headers(PlatformConnection $connection): array
    {
        $tokens = is_array($connection->tokens) ? $connection->tokens : [];
        $managerToken = is_string($tokens['manager_token'] ?? null) ? $tokens['manager_token'] : '';

        return [
            'Store-Id' => (string) $connection->external_store_id,
            'Role' => 'Manager',
            'Access-Token' => $managerToken,
            'Accept-Language' => 'ar',
        ];
    }

    /**
     * @return list<StoreProduct>
     */
    private function products(mixed $results): array
    {
        if (! is_array($results)) {
            return [];
        }

        $items = array_values(array_filter($results, 'is_array'));

        $products = [];
        foreach ($items as $item) {
            try {
                $products[] = StoreProduct::fromZid($item);
            } catch (Throwable) {
                continue;
            }
        }

        return $products;
    }

    private function nextPage(array $json, int $page): ?int
    {
        // Zid paginates with a `next` URL (or null on the last page).
        $next = $json['next'] ?? null;

        if (is_string($next) && trim($next) !== '') {
            return $page + 1;
        }

        if ($next !== null && $next !== false && $next !== '') {
            return $page + 1;
        }

        $count = is_numeric($json['count'] ?? null) ? (int) $json['count'] : null;
        $results = is_array($json['results'] ?? null) ? $json['results'] : [];

        if ($count !== null) {
            return $page * 50 < $count ? $page + 1 : null;
        }

        return count($results) >= 50 ? $page + 1 : null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>|null
     */
    private function unwrap(array $json): ?array
    {
        foreach (['data', 'product', 'result'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return $json[$key];
            }
        }

        return array_key_exists('id', $json) ? $json : null;
    }
}
