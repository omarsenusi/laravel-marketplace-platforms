<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies;

use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationResult;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationStrategy;

class BasicAuthPasswordStrategy implements VerificationStrategy
{
    public function id(): string
    {
        return 'basic_auth_password';
    }

    public function verify(VerificationContext $ctx): VerificationResult
    {
        $secretRef = (string) ($ctx->config['secretRef'] ?? 'webhook_secret');

        $provided = $ctx->header('authorization');

        if (! is_string($provided) || $provided === '') {
            return VerificationResult::fail($this->id(), "missing header 'authorization'");
        }

        if (! str_starts_with($provided, 'Basic ')) {
            return VerificationResult::fail($this->id(), 'missing Basic prefix');
        }

        $decoded = base64_decode(substr($provided, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return VerificationResult::fail($this->id(), 'malformed Basic credentials');
        }

        [, $password] = explode(':', $decoded, 2);

        $secret = $ctx->secret($secretRef);

        if (! is_string($secret) || $secret === '') {
            return VerificationResult::fail($this->id(), "secret '{$secretRef}' is not set");
        }

        if (! hash_equals($secret, (string) $password)) {
            return VerificationResult::fail($this->id(), 'password mismatch');
        }

        return VerificationResult::ok($this->id());
    }

    public function describe(): array
    {
        return [
            'label' => 'Basic-auth password',
            'description' => 'Decodes "Authorization: Basic base64(user:pass)" and compares the password part against a stored secret.',
            'fields' => [
                ['key' => 'secretRef', 'type' => 'secret_ref', 'default' => 'webhook_secret', 'required' => true],
            ],
        ];
    }
}
