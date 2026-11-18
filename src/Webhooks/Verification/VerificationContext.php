<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification;

/**
 * Inputs a strategy may inspect. Headers are keyed lowercase.
 *
 * The secret resolver maps a reference name to its clear-text value:
 *  - 'client_secret' | 'webhook_secret' | 'partner_api_key' resolve from
 *    the platform's PlatformSecret row (null when not set);
 *  - 'app_id' | 'client_id' resolve from the Platform row itself.
 *
 * @phpstan-type SecretResolver callable(string): ?string
 */
final class VerificationContext
{
    /**
     * @param  array<string, string>  $headers  Lower-cased header name => value.
     * @param  array<string, mixed>  $config  Endpoint verification config.
     */
    public function __construct(
        public readonly string $rawBody,
        public readonly array $headers,
        public readonly array $config,
        public readonly \Closure $secretResolver,
    ) {}

    public function secret(string $ref): ?string
    {
        return ($this->secretResolver)($ref);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
