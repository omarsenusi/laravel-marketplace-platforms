<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification;

interface VerificationStrategy
{
    /**
     * Stable id used in endpoint.verification_strategy and in composite
     * children ({id, config}).
     */
    public function id(): string;

    public function verify(VerificationContext $ctx): VerificationResult;

    /**
     * Configuration UI schema describing the strategy: shown by host
     * tooling when building endpoint forms.
     *
     * @return array{label: string, description: string, fields: list<array{key: string, type: 'text'|'select'|'secret_ref'|'bool'|'list', options?: list<string>, default?: mixed, required?: bool}>}
     */
    public function describe(): array;
}
