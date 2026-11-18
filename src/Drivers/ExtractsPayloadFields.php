<?php

namespace Omarsenusi\MarketplacePlatforms\Drivers;

/**
 * Shared, config-driven payload extraction. Key lists come from the
 * driver's defaultConfig() (storeIdPayloadKeys, eventNamePayloadKeys,
 * eventIdPayloadKeys) — dot paths, first non-empty wins.
 */
trait ExtractsPayloadFields
{
    /**
     * @param  array<string, mixed>  $config  Driver config override (platform.config merged over defaults). Pass [] to use driver defaults.
     * @return list<string>
     */
    private function payloadKeys(array $config, string $key): array
    {
        $fromConfig = $config[$key] ?? null;

        if (is_array($fromConfig) && $fromConfig !== []) {
            return array_values(array_filter(array_map(strval(...), $fromConfig)));
        }

        $defaults = $this->defaultConfig()[$key] ?? [];

        return is_array($defaults) ? array_values(array_filter(array_map(strval(...), $defaults))) : [];
    }

    private function firstNonEmpty(array $payload, array $keys): ?string
    {
        foreach ($keys as $path) {
            $value = data_get($payload, $path);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storeId(array $payload, array $config = []): ?string
    {
        return $this->firstNonEmpty($payload, $this->payloadKeys($config, 'storeIdPayloadKeys'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function topic(array $payload, array $config = []): ?string
    {
        return $this->firstNonEmpty($payload, $this->payloadKeys($config, 'eventNamePayloadKeys'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function externalEventId(array $payload, string $rawBody, array $config = []): string
    {
        return $this->firstNonEmpty($payload, $this->payloadKeys($config, 'eventIdPayloadKeys'))
            ?? hash('sha256', $rawBody);
    }
}
