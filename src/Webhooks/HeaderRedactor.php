<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks;

/**
 * Redacts secret-bearing headers before a delivery is persisted, so the
 * stored rows never keep raw signatures, tokens, or keys.
 */
final class HeaderRedactor
{
    /**
     * Headers that are always redacted (lowercase).
     *
     * @var list<string>
     */
    private const ALWAYS_REDACT = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-salla-signature',
        'x-zid-api-key',
        'x-zid-signature',
    ];

    /**
     * @param  array<string, string>  $headers  Lower-cased header name => value.
     * @param  array<string, mixed>  $verificationConfig
     * @return array<string, string>
     */
    public static function redact(array $headers, array $verificationConfig): array
    {
        $sensitive = array_merge(self::ALWAYS_REDACT, self::configHeaders($verificationConfig));

        $redacted = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            $redacted[$lower] = in_array($lower, $sensitive, true) ? '[REDACTED]' : $value;
        }

        return $redacted;
    }

    /**
     * Collect every header name referenced by a verification config,
     * recursing through composite children.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function configHeaders(array $config, int $depth = 0): array
    {
        $headers = [];

        if (isset($config['header']) && is_string($config['header']) && $config['header'] !== '') {
            $headers[] = strtolower($config['header']);
        }

        if ($depth < 3) {
            $children = $config['strategies'] ?? $config['children'] ?? [];

            if (is_array($children)) {
                foreach ($children as $child) {
                    if (is_array($child) && isset($child['config']) && is_array($child['config'])) {
                        $headers = array_merge($headers, self::configHeaders($child['config'], $depth + 1));
                    }
                }
            }
        }

        return $headers;
    }
}
