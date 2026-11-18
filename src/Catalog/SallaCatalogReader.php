<?php

namespace Omarsenusi\MarketplacePlatforms\Catalog;

use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Throwable;

/**
 * Salla Admin API catalogue reader (base https://api.salla.dev/admin/v2).
 *
 * GET /products?page=N&per_page=50[&keyword=…] → {data:[…], pagination:{…}}.
 * GET /products/{id} → {data:{…}}. Never throws; null/miss on failure.
 */
final class SallaCatalogReader implements CatalogReader
{
    use RemembersDeferral;

    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function page(PlatformConnection $connection, int $page, string $lane): CatalogPage
    {
        try {
            $result = $this->gateway->get(
                $connection, '/products',
                ['page' => max(1, $page), 'per_page' => 50],
                $lane,
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

        $data = $result->json['data'] ?? null;
        $items = is_array($data) ? array_values(array_filter($data, 'is_array')) : [];

        $products = [];
        foreach ($items as $item) {
            try {
                $products[] = StoreProduct::fromSalla($item);
            } catch (Throwable) {
                continue;
            }
        }

        return CatalogPage::ok($products, $this->nextPage($result->json, max(1, $page), count($items)));
    }

    public function find(PlatformConnection $connection, string $id, string $lane): ?StoreProduct
    {
        $this->markResolved();

        if (trim($id) === '') {
            return null;
        }

        try {
            $result = $this->gateway->get(
                $connection, '/products/'.rawurlencode(trim($id)),
                [], $lane, $lane === 'chat' ? 60 : 0,
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

        $data = $result->json['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        try {
            return StoreProduct::fromSalla($data);
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
                $connection, '/products',
                ['page' => 1, 'per_page' => 50, 'keyword' => $query],
                $lane, $lane === 'chat' ? 60 : 0,
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

        $data = $result->json['data'] ?? null;
        $items = is_array($data) ? array_values(array_filter($data, 'is_array')) : [];

        $products = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            try {
                $products[] = StoreProduct::fromSalla($item);
            } catch (Throwable) {
                continue;
            }
        }

        return CatalogPage::ok($products);
    }

    private function nextPage(array $json, int $page, int $count): ?int
    {
        $pagination = is_array($json['pagination'] ?? null) ? $json['pagination'] : [];

        foreach (['next', 'next_page', 'nextPage'] as $key) {
            $next = $pagination[$key] ?? null;

            if (is_numeric($next) && (int) $next > $page) {
                return (int) $next;
            }

            if ($next === true) {
                return $page + 1;
            }
        }

        // Defensive fallbacks: explicit current/total pages, else assume
        // a full page means more (bounded by the caller's 200-page cap).
        $current = $pagination['current'] ?? $pagination['current_page'] ?? null;
        $total = $pagination['total_pages'] ?? $pagination['pages'] ?? null;

        if (is_numeric($current) && is_numeric($total)) {
            return ((int) $current < (int) $total) ? ((int) $current + 1) : null;
        }

        return $count >= 50 ? $page + 1 : null;
    }
}
