<?php

namespace Omarsenusi\MarketplacePlatforms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;

/**
 * Fired after a workspace claim completes (password set or
 * forgot-password reset for a null-password user).
 */
final class WorkspaceClaimed
{
    use Dispatchable;

    public function __construct(
        public readonly WorkspaceClaim $claim,
        public readonly string $via,
    ) {}
}
