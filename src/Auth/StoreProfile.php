<?php

namespace Omarsenusi\MarketplacePlatforms\Auth;

/**
 * Normalized store profile as returned by a marketplace API.
 * Every field is nullable — marketplace payloads are defensive by
 * design (keys may be missing, localized {ar,en} objects, etc.).
 */
final class StoreProfile
{
    /**
     * @param  array<string, mixed>  $metadata  Raw payload kept for debugging (never tokens).
     */
    public function __construct(
        public readonly string $externalStoreId,
        public readonly ?string $storeName = null,
        public readonly ?string $storeDomain = null,
        public readonly ?string $storeUrl = null,
        public readonly ?string $ownerName = null,
        public readonly ?string $ownerEmail = null,
        public readonly ?string $ownerPhone = null,
        public readonly ?string $currency = null,
        public readonly ?string $locale = null,
        public readonly array $metadata = [],
    ) {}

    public function hasEmail(): bool
    {
        return is_string($this->ownerEmail) && trim($this->ownerEmail) !== '';
    }

    /**
     * Update store-settings attributes with REAL data only: blanks and
     * placeholders never overwrite stored values.
     *
     * @param  array<string, mixed>  $attributes  Existing settings attributes.
     * @return array<string, mixed>
     */
    public function mergeIntoSettings(array $attributes): array
    {
        $candidate = [
            'store_name' => $this->storeName,
            'store_domain' => $this->storeDomain,
            'store_url' => $this->storeUrl,
            'owner_name' => $this->ownerName,
            'owner_email' => $this->ownerEmail,
            'owner_phone' => $this->ownerPhone,
            'currency' => $this->currency,
            'locale' => $this->locale,
        ];

        foreach ($candidate as $key => $value) {
            if (is_string($value) && trim($value) !== '' && ! self::isPlaceholder($value)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    private static function isPlaceholder(string $value): bool
    {
        $lower = strtolower(trim($value));

        return in_array($lower, ['unknown', 'n/a', 'na', '-', 'null', 'undefined', ''], true)
            || str_contains($lower, 'placeholder')
            || str_contains($lower, 'example.com');
    }

    /**
     * Read a possibly-localized string ({ar:…, en:…} or plain string).
     */
    public static function localized(mixed $value, ?string $preferredLocale = null): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_array($value)) {
            foreach ([$preferredLocale, 'en', 'ar'] as $locale) {
                if ($locale !== null && isset($value[$locale]) && is_string($value[$locale]) && trim($value[$locale]) !== '') {
                    return $value[$locale];
                }
            }

            foreach ($value as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
