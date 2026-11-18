<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies;

use InvalidArgumentException;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\StrategyRegistry;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationContext;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationResult;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\VerificationStrategy;

class CompositeAnyStrategy implements VerificationStrategy
{
    public function id(): string
    {
        return 'composite_any';
    }

    public function verify(VerificationContext $ctx): VerificationResult
    {
        /** @var mixed $children */
        $children = $ctx->config['strategies'] ?? $ctx->config['children'] ?? [];

        if (! is_array($children) || $children === []) {
            return VerificationResult::fail($this->id(), 'no child strategies configured');
        }

        $reasons = [];

        foreach ($children as $child) {
            if (! is_array($child) || ! isset($child['id'])) {
                $reasons[] = 'malformed child definition';

                continue;
            }

            try {
                $strategy = app(StrategyRegistry::class)->resolve((string) $child['id']);
            } catch (InvalidArgumentException $e) {
                $reasons[] = "unknown strategy '{$child['id']}'";

                continue;
            }

            $childConfig = is_array($child['config'] ?? null) ? $child['config'] : [];

            $result = $strategy->verify(new VerificationContext(
                $ctx->rawBody,
                $ctx->headers,
                $childConfig,
                $ctx->secretResolver,
            ));

            if ($result->valid) {
                return VerificationResult::ok($this->id());
            }

            $reasons[] = "{$result->strategyId}: {$result->reason}";
        }

        return VerificationResult::fail($this->id(), 'all child strategies failed ('.implode('; ', $reasons).')');
    }

    public function describe(): array
    {
        return [
            'label' => 'Any of (composite)',
            'description' => 'Valid when ANY child strategy validates. Use when a marketplace may sign with either of two schemes (e.g. Salla signature or token).',
            'fields' => [
                ['key' => 'strategies', 'type' => 'list', 'required' => true],
            ],
        ];
    }
}
