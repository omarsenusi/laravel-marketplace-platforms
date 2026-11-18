<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies;

use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationResult;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationStrategy;

class StaticHeaderStrategy implements VerificationStrategy
{
    public function id(): string
    {
        return 'static_header';
    }

    public function verify(VerificationContext $ctx): VerificationResult
    {
        $header = (string) ($ctx->config['header'] ?? 'x-zid-api-key');
        $expectedRef = (string) ($ctx->config['expectedRef'] ?? 'partner_api_key');

        $provided = $ctx->header($header);

        if (! is_string($provided) || $provided === '') {
            return VerificationResult::fail($this->id(), "missing header '{$header}'");
        }

        $expected = $ctx->secret($expectedRef);

        if (! is_string($expected) || $expected === '') {
            return VerificationResult::fail($this->id(), "secret '{$expectedRef}' is not set");
        }

        if (! hash_equals($expected, $provided)) {
            return VerificationResult::fail($this->id(), 'header value mismatch');
        }

        return VerificationResult::ok($this->id());
    }

    public function describe(): array
    {
        return [
            'label' => 'Static API-key header',
            'description' => 'Compares a custom header (e.g. Zid App Market x-zid-api-key) against a stored secret.',
            'fields' => [
                ['key' => 'header', 'type' => 'text', 'default' => 'x-zid-api-key', 'required' => true],
                ['key' => 'expectedRef', 'type' => 'secret_ref', 'default' => 'partner_api_key', 'required' => true],
            ],
        ];
    }
}
