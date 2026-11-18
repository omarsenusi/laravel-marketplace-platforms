<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies;

use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationResult;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationStrategy;

class HmacSha256Strategy implements VerificationStrategy
{
    public function id(): string
    {
        return 'hmac_sha256';
    }

    public function verify(VerificationContext $ctx): VerificationResult
    {
        $header = (string) ($ctx->config['header'] ?? 'x-salla-signature');
        $encoding = strtolower((string) ($ctx->config['encoding'] ?? 'hex'));
        $secretRef = (string) ($ctx->config['secretRef'] ?? 'webhook_secret');

        $provided = $ctx->header($header);

        if (! is_string($provided) || $provided === '') {
            return VerificationResult::fail($this->id(), "missing header '{$header}'");
        }

        $secret = $ctx->secret($secretRef);

        if (! is_string($secret) || $secret === '') {
            return VerificationResult::fail($this->id(), "secret '{$secretRef}' is not set");
        }

        $expected = hash_hmac('sha256', $ctx->rawBody, $secret);

        if ($encoding === 'base64') {
            $expected = base64_encode(hash_hmac('sha256', $ctx->rawBody, $secret, true));
        }

        $candidate = $provided;

        if (str_starts_with(strtolower($candidate), 'sha256=')) {
            $candidate = substr($candidate, 7);
        }

        if ($encoding !== 'base64') {
            $candidate = strtolower(trim($candidate));
        }

        if (! hash_equals($expected, $candidate)) {
            return VerificationResult::fail($this->id(), 'signature mismatch');
        }

        return VerificationResult::ok($this->id());
    }

    public function describe(): array
    {
        return [
            'label' => 'HMAC-SHA256 signature',
            'description' => 'HMAC-SHA256 over the raw request body, compared against a signature header. Supports an optional "sha256=" prefix.',
            'fields' => [
                ['key' => 'header', 'type' => 'text', 'default' => 'x-salla-signature', 'required' => true],
                ['key' => 'encoding', 'type' => 'select', 'options' => ['hex', 'base64'], 'default' => 'hex', 'required' => true],
                ['key' => 'secretRef', 'type' => 'secret_ref', 'default' => 'webhook_secret', 'required' => true],
            ],
        ];
    }
}
