<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Minor-units (halalas) <-> Salla decimal amounts (2 dp) at the HTTP edge.
 *
 * Salla sends prices as {amount, currency} numbers (sometimes
 * numbers-as-strings, e.g. cost_price "35.00") and accepts bare decimal
 * numbers in write bodies. DTOs never see those shapes.
 */
final class SallaMoney
{
    /**
     * 9633 halalas → "96.33" for a Salla write body.
     */
    public static function toDecimal(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }

    /**
     * Salla {amount, currency} (or a bare number) → Money. Null when the
     * amount is missing or non-numeric; currency falls back to $default.
     */
    public static function fromAmount(mixed $amount, mixed $currency = null, ?string $default = null): ?Money
    {
        if (is_array($amount)) {
            $currency ??= $amount['currency'] ?? null;
            $amount = $amount['amount'] ?? null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        $code = is_string($currency) && preg_match('/^[A-Za-z]{3}$/', trim($currency))
            ? strtoupper(trim($currency))
            : $default;

        if ($code === null) {
            return null;
        }

        return new Money((int) round((float) $amount * 100), $code);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row, string $key = 'price', ?string $defaultCurrency = null): ?Money
    {
        if (! array_key_exists($key, $row)) {
            return null;
        }

        $currency = $row['currency'] ?? null;
        $currency = is_string($currency) ? $currency : null;

        return self::fromAmount($row[$key], $currency, $defaultCurrency);
    }
}
