<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies;

use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationResult;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationStrategy;

class NoneStrategy implements VerificationStrategy
{
    public function id(): string
    {
        return 'none';
    }

    public function verify(VerificationContext $ctx): VerificationResult
    {
        if (! app()->environment(['local', 'testing'])) {
            return VerificationResult::fail($this->id(), 'none_strategy_disabled_in_production');
        }

        return VerificationResult::ok($this->id());
    }

    public function describe(): array
    {
        return [
            'label' => 'None (local/testing only)',
            'description' => 'Accepts every request. Only usable when APP_ENV is local or testing; rejected everywhere else.',
            'fields' => [],
        ];
    }
}
