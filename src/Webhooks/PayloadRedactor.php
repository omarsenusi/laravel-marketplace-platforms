<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks;

/**
 * Scrubs credentials out of webhook payloads (Salla app.store.authorize
 * carries data.access_token / data.refresh_token in the body).
 *
 * The raw payload is needed until the handler has run (the authorize
 * handler reads the tokens from it), so persistence scrubs only once a
 * delivery is final (processed / rejected / ignored). Anything shown
 * to a host operator is always scrubbed.
 */
final class PayloadRedactor
{
    public const MASK = '[REDACTED]';

    /**
     * Exact key names (case-insensitive) whose values are secrets.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = [
        'access_token',
        'refresh_token',
        'id_token',
        'manager_token',
        'authorization',
        'authorization_token',
        'client_secret',
        'secret',
        'password',
        'api_key',
        'apikey',
    ];

    public static function redact(mixed $payload, int $depth = 0): mixed
    {
        if (! is_array($payload) || $depth > 12) {
            return $payload;
        }

        $out = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)
                && $value !== null && $value !== '') {
                $out[$key] = self::MASK;

                continue;
            }

            $out[$key] = self::redact($value, $depth + 1);
        }

        return $out;
    }
}
