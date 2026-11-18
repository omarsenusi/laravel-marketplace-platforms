<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies;

use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationResult;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationStrategy;

class BearerTokenStrategy implements VerificationStrategy
{
    public function id(): string
    {
        return 'bearer_token';
    }

    public function verify(VerificationContext $ctx): VerificationResult
    {
        $header = (string) ($ctx->config['header'] ?? 'authorization');
        $stripPrefix = (bool) ($ctx->config['stripBearerPrefix'] ?? true);
        $secretRef = (string) ($ctx->config['secretRef'] ?? 'webhook_secret');

        $provided = $ctx->header($header);

        if (! is_string($provided) || $provided === '') {
            return VerificationResult::fail($this->id(), "missing header '{$header}'");
        }

        $secret = $ctx->secret($secretRef);

        if (! is_string($secret) || $secret === '') {
            return VerificationResult::fail($this->id(), "secret '{$secretRef}' is not set");
        }

        $candidate = $provided;

        if ($stripPrefix) {
            if (str_starts_with($candidate, 'Bearer ')) {
                $candidate = substr($candidate, 7);
            } else {
                return VerificationResult::fail($this->id(), 'missing Bearer prefix');
            }
        }

        if (! hash_equals($secret, $candidate)) {
            return VerificationResult::fail($this->id(), 'token mismatch');
        }

        return VerificationResult::ok($this->id());
    }

    public function describe(): array
    {
        return [
            'label' => 'Bearer token',
            'description' => 'Compares the secret against an "Authorization: Bearer <secret>" header.',
            'fields' => [
                ['key' => 'header', 'type' => 'text', 'default' => 'authorization', 'required' => true],
                ['key' => 'stripBearerPrefix', 'type' => 'bool', 'default' => true, 'required' => false],
                ['key' => 'secretRef', 'type' => 'secret_ref', 'default' => 'webhook_secret', 'required' => true],
            ],
        ];
    }
}
