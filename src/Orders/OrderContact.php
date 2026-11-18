<?php

namespace Omarsenusi\MarketplacePlatforms\Orders;

/**
 * Visitor-contact verification for order lookups.
 *
 * Email path: the visitor string contains '@' → case-insensitive exact
 * match against the order email. Anything masked (contains '*') or
 * missing on the order side is unusable → mismatch.
 *
 * Phone path: digits-only comparison of the LAST 9 digits (Saudi
 * mobiles look like 05XXXXXXXX locally vs +9665XXXXXXXX on file).
 * An order phone with fewer than 7 digits (or masked) is unusable.
 */
final class OrderContact
{
    public static function matches(string $visitorContact, mixed $orderEmail, mixed $orderPhone): bool
    {
        $visitor = trim($visitorContact);

        if (str_contains($visitor, '@')) {
            $want = mb_strtolower($visitor);

            if (! is_string($orderEmail) || trim($orderEmail) === '') {
                return false;
            }

            $have = trim($orderEmail);

            if (str_contains($have, '*')) {
                return false;
            }

            return mb_strtolower($have) === $want;
        }

        $wantDigits = preg_replace('/\D+/', '', $visitor) ?? '';

        if (strlen($wantDigits) < 7) {
            return false;
        }

        $haveRaw = is_string($orderPhone) || is_numeric($orderPhone) ? (string) $orderPhone : '';

        if ($haveRaw === '' || str_contains($haveRaw, '*')) {
            return false;
        }

        $haveDigits = preg_replace('/\D+/', '', $haveRaw) ?? '';

        if (strlen($haveDigits) < 7) {
            return false;
        }

        // Compare the national-number tail: 9 digits for KSA/UAE, but
        // Kuwait/Bahrain/Qatar/Oman mobiles are 8 — a visitor typing the
        // local 8-digit number must still match "+965 9xxxxxxx".
        $n = min(9, strlen($wantDigits), strlen($haveDigits));

        return substr($wantDigits, -$n) === substr($haveDigits, -$n);
    }

    public static function phoneDigits(mixed ...$parts): string
    {
        $digits = '';

        foreach ($parts as $part) {
            if (is_string($part) || is_numeric($part)) {
                $digits .= (string) $part;
            }
        }

        return preg_replace('/\D+/', '', $digits) ?? '';
    }
}
