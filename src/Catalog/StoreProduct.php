<?php

namespace Omarsenusi\MarketplacePlatforms\Catalog;

use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;

/**
 * Normalized store product, shared by the Salla + Zid readers.
 *
 * WHY a readonly value object: the two marketplace payloads differ
 * wildly (Salla price {amount,currency} vs Zid bare numbers, localized
 * {ar,en} maps, light vs full image shapes), but the indexer, the jobs
 * and the MCP tools all need ONE shape. All parsing is defensive —
 * missing keys, numbers-as-strings and localized maps are handled.
 *
 * Stock rule: stock is null when unlimited or unknown; inStock is true
 * when unlimited. indexText() NEVER carries stock/quantity — stock
 * changes on every sale and must not re-trigger embeddings.
 */
final readonly class StoreProduct
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public ?float $price,
        public ?float $regularPrice,
        public ?string $currency,
        public ?string $url,
        public ?string $image,
        public ?bool $inStock,
        public ?int $stock,
        public array $categories,
        public ?string $brand,
        public ?string $sku,
        public bool $visible,
    ) {}

    public static function fromSalla(array $raw): self
    {
        $id = self::scalar($raw['id'] ?? null) ?? '';
        $name = self::localized($raw['name'] ?? null) ?? (string) $id;
        $description = self::cleanHtml(self::localized($raw['description'] ?? null) ?? '');

        $priceRaw = is_array($raw['price'] ?? null) ? $raw['price'] : [];
        $saleRaw = is_array($raw['sale_price'] ?? null) ? $raw['sale_price'] : [];

        $regular = self::number($priceRaw['amount'] ?? null);
        $sale = self::number($saleRaw['amount'] ?? null);
        $effective = self::effectivePrice($regular, $sale);

        $currency = self::currencyCode($priceRaw['currency'] ?? null)
            ?? self::currencyCode($saleRaw['currency'] ?? null);

        $status = is_string($raw['status'] ?? null) ? strtolower(trim($raw['status'])) : '';
        $visible = ! in_array($status, ['hidden', 'deleted'], true);

        $unlimited = ($raw['unlimited_quantity'] ?? null) === true;
        $quantity = self::intVal($raw['quantity'] ?? null);
        $available = $raw['is_available'] ?? null;
        $available = is_bool($available) ? $available : null;

        if ($unlimited) {
            $stock = null;
            $inStock = $available ?? true;
        } elseif ($quantity !== null) {
            $stock = $quantity;
            $inStock = $available ?? ($quantity > 0);
        } else {
            $stock = null;
            $inStock = $available;
        }

        return new self(
            id: (string) $id,
            name: $name,
            description: $description,
            price: $effective,
            regularPrice: $regular,
            currency: $currency,
            url: self::sallaUrl($raw),
            image: self::sallaImage($raw),
            inStock: $inStock,
            stock: $stock,
            categories: self::names($raw['categories'] ?? null),
            brand: self::brandName($raw['brand'] ?? null),
            sku: self::scalar($raw['sku'] ?? null),
            visible: $visible,
        );
    }

    public static function fromZid(array $raw): self
    {
        $id = self::scalar($raw['id'] ?? null) ?? '';
        $name = self::localized($raw['name'] ?? null) ?? (string) $id;
        $description = self::cleanHtml(self::localized($raw['description'] ?? null) ?? '');

        $regular = self::number($raw['price'] ?? null);
        $sale = self::number($raw['sale_price'] ?? null);
        $effective = self::effectivePrice($regular, $sale);

        $published = $raw['is_published'] ?? null;
        $visible = $published === null ? true : (bool) $published;

        $infinite = ($raw['is_infinite'] ?? null) === true;
        $quantity = self::intVal($raw['quantity'] ?? null);

        if ($infinite) {
            $stock = null;
            $inStock = true;
        } elseif ($quantity !== null) {
            $stock = $quantity;
            $inStock = $quantity > 0;
        } else {
            $stock = null;
            $inStock = null;
        }

        return new self(
            id: (string) $id,
            name: $name,
            description: $description,
            price: $effective,
            regularPrice: $regular,
            currency: self::currencyCode($raw['currency'] ?? null),
            url: self::zidUrl($raw),
            image: self::zidImage($raw),
            inStock: $inStock,
            stock: $stock,
            categories: self::names($raw['categories'] ?? null),
            brand: self::brandName($raw['brand'] ?? null),
            sku: self::scalar($raw['sku'] ?? null),
            visible: $visible,
        );
    }

    /**
     * Tool-facing shape: stable keys (id, name, price, currency,
     * image, product_url, stock, in_stock, category, brand) plus a
     * short summary.
     *
     * @return array<string, mixed>
     */
    public function toToolArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'regular_price' => $this->regularPrice,
            'currency' => $this->currency,
            'image' => $this->image,
            'product_url' => $this->url,
            'in_stock' => $this->inStock,
            'stock' => $this->stock,
            'category' => $this->categories[0] ?? null,
            'brand' => $this->brand,
            'summary' => mb_substr($this->description, 0, 160),
        ];
    }

    /**
     * Thin-card text for the RAG index. Arabic labels are fine.
     * Price is a BAND (not the exact price) and stock is never
     * included — both change constantly and must not churn embeddings.
     */
    public function indexText(): string
    {
        $lines = ['المنتج: '.$this->name];

        if ($this->categories !== []) {
            $lines[] = 'التصنيفات: '.implode('، ', $this->categories);
        }

        if ($this->brand !== null && $this->brand !== '') {
            $lines[] = 'العلامة التجارية: '.$this->brand;
        }

        if ($this->sku !== null && $this->sku !== '') {
            $lines[] = 'SKU: '.$this->sku;
        }

        $lines[] = 'نطاق السعر: '.self::priceBand($this->price).($this->currency !== null ? ' '.$this->currency : '');

        if ($this->description !== '') {
            $lines[] = mb_substr($this->description, 0, 600);
        }

        if ($this->url !== null && $this->url !== '') {
            $lines[] = 'الرابط: '.$this->url;
        }

        return implode("\n", $lines);
    }

    public function contentHash(): string
    {
        return hash('sha256', $this->indexText());
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function priceBandBounds(?float $price): array
    {
        $price = $price ?? 0.0;

        return match (true) {
            $price < 50 => ['0', '50'],
            $price < 100 => ['50', '100'],
            $price < 200 => ['100', '200'],
            $price < 500 => ['200', '500'],
            $price < 1000 => ['500', '1000'],
            $price < 2500 => ['1000', '2500'],
            default => ['2500', '+'],
        };
    }

    public static function priceBand(?float $price): string
    {
        [$low, $high] = self::priceBandBounds($price);

        return $high === '+' ? $low.'+' : $low.'–'.$high;
    }

    private static function effectivePrice(?float $regular, ?float $sale): ?float
    {
        if ($sale !== null && $sale > 0 && ($regular === null || $sale < $regular)) {
            return $sale;
        }

        return $regular;
    }

    private static function scalar(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private static function intVal(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    private static function currencyCode(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^[A-Za-z]{3}$/', trim($value))) {
            return strtoupper(trim($value));
        }

        return null;
    }

    /**
     * Localized string — Arabic first (the catalogue is mostly Arabic).
     */
    private static function localized(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach (['ar', 'en'] as $locale) {
                if (isset($value[$locale]) && is_string($value[$locale]) && trim($value[$locale]) !== '') {
                    return trim($value[$locale]);
                }
            }

            foreach ($value as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return null;
    }

    private static function cleanHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Tags → spaces first: size tables (<td>36</td><td>130</td>) would
        // otherwise collapse into one meaningless token ("36130…").
        $text = strip_tags((string) preg_replace('/<[^>]+>/u', '$0 ', $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);

                continue;
            }

            if (is_array($item)) {
                $name = self::localized($item['name'] ?? null);

                if ($name !== null) {
                    $out[] = $name;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function brandName(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_array($value)) {
            return self::localized($value['name'] ?? null);
        }

        return null;
    }

    private static function sallaUrl(array $raw): ?string
    {
        $url = self::scalar($raw['url'] ?? null);

        if ($url !== null) {
            return $url;
        }

        $urls = is_array($raw['urls'] ?? null) ? $raw['urls'] : [];

        return self::scalar($urls['customer'] ?? null)
            ?? self::scalar($urls['admin'] ?? null);
    }

    private static function sallaImage(array $raw): ?string
    {
        $main = self::scalar($raw['main_image'] ?? null);

        if ($main !== null) {
            return $main;
        }

        $images = $raw['images'] ?? null;

        if (is_array($images)) {
            foreach ($images as $image) {
                if (is_string($image) && trim($image) !== '') {
                    return trim($image);
                }

                if (is_array($image)) {
                    $url = self::scalar($image['url'] ?? null)
                        ?? self::scalar($image['main_image'] ?? null)
                        ?? self::scalar($image['src'] ?? null);

                    if ($url !== null) {
                        return $url;
                    }
                }
            }
        }

        return self::scalar($raw['thumbnail'] ?? null);
    }

    private static function zidUrl(array $raw): ?string
    {
        $url = self::scalar($raw['html_url'] ?? null);

        if ($url !== null) {
            return $url;
        }

        $slug = self::scalar($raw['slug'] ?? null);

        if ($slug !== null && preg_match('#^https?://#i', $slug)) {
            return $slug;
        }

        return null;
    }

    private static function zidImage(array $raw): ?string
    {
        $images = $raw['images'] ?? null;

        if (! is_array($images)) {
            return null;
        }

        foreach ($images as $image) {
            $node = is_array($image) && isset($image['image']) && is_array($image['image'])
                ? $image['image']
                : (is_array($image) ? $image : null);

            if (! is_array($node)) {
                if (is_string($image) && trim($image) !== '') {
                    return trim($image);
                }

                continue;
            }

            foreach (['full_size', 'large', 'medium', 'small', 'thumbnail', 'url', 'src'] as $key) {
                $url = self::scalar($node[$key] ?? null);

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Expose StoreProfile::localized for tests without duplicating logic.
     */
    public static function preferArabic(mixed $value): ?string
    {
        return StoreProfile::localized($value, 'ar');
    }
}
