<?php

namespace Omarsenusi\MarketplacePlatforms\Services;

use Illuminate\Database\Eloquent\Model;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Outcome of Provisioner::provision().
 */
final class ProvisionResult
{
    public function __construct(
        public readonly PlatformConnection $connection,
        public readonly ?Model $user,
        public readonly bool $isNewConnection,
        public readonly bool $isNewUser,
        /** Raw claim token when a fresh claim was issued (OAuth callbacks redirect straight to it). */
        public readonly ?string $claimToken = null,
    ) {}
}
