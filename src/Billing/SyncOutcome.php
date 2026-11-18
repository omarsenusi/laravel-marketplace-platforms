<?php

namespace Omarsenusi\MarketplacePlatforms\Billing;

/**
 * What SubscriptionSync actually did. `needsAction` marks outcomes the
 * host must fix (e.g. an unmapped plan) so a stored delivery can flag
 * them instead of showing a plain "processed".
 */
final class SyncOutcome
{
    public function __construct(
        public readonly string $message,
        public readonly bool $needsAction = false,
    ) {}
}
