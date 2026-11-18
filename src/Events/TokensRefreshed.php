<?php

namespace Omarsenusi\MarketplacePlatforms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Fired after a connection's tokens are created or refreshed
 * (install, OAuth callback, scheduled refresh).
 */
final class TokensRefreshed
{
    use Dispatchable;

    public function __construct(
        public readonly PlatformConnection $connection,
    ) {}
}
