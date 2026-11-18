<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies;

use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationResult;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationStrategy;

class PlainHeaderStrategy implements VerificationStrategy
{
    public function id(): string
    {
        return 'plain_header';
    }

    public function verify(VerificationContext $ctx): VerificationResult
    {
        $header = (string) ($ctx->config['header'] ?? 'authorization');
        $secretRef = (string) ($ctx->config['secretRef'] ?? 'webhook_secret');

        $provided = $ctx->header($header);

        if (! is_string($provided) || $provided === '') {
            return VerificationResult::fail($this->id(), "missing header '{$header}'");
        }

        $secret = $ctx->secret($secretRef);

        if (! is_string($secret) || $secret === '') {
            return VerificationResult::fail($this->id(), "secret '{$secretRef}' is not set");
        }

        if (! hash_equals($secret, $provided)) {
            return VerificationResult::fail($this->id(), 'header value mismatch');
        }

        return VerificationResult::ok($this->id());
    }

    public function describe(): array
    {
        return [
            'label' => 'Plain header token',
            'description' => 'The header value IS the secret, compared exactly (no Bearer prefix). This is Salla\'s "token" strategy.',
            'fields' => [
                ['key' => 'header', 'type' => 'text', 'default' => 'authorization', 'required' => true],
                ['key' => 'secretRef', 'type' => 'secret_ref', 'default' => 'webhook_secret', 'required' => true],
            ],
        ];
    }
}
