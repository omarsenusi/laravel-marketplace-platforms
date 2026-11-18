<?php

namespace Omarsenusi\MarketplacePlatforms\Services;

use Illuminate\Support\Facades\DB;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Auth\TokenStore;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Events\StoreUninstalled;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Uninstall: connection → uninstalled (tokens cleared, schedules
 * nulled, open claims expired). The user + workspace are NEVER
 * deleted — a reinstall reactivates the same rows. The host observes
 * via the StoreUninstalled event.
 */
final class Uninstaller
{
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly ClaimService $claims,
    ) {}

    public function uninstall(PlatformConnection $connection, string $source): PlatformConnection
    {
        return DB::transaction(function () use ($connection, $source): PlatformConnection {
            $connection->forceFill([
                'status' => ConnectionStatus::Uninstalled,
                'uninstalled_at' => now(),
                // The Zid embedded launch uuid is a long-lived bearer
                // proof: it must die with the install (a reinstall
                // registers a fresh one).
                'embedded_token_hash' => null,
            ])->save();

            $this->tokens->clear($connection->refresh());
            $this->claims->expireConnectionClaims($connection->refresh());

            StoreUninstalled::dispatch($connection->refresh(), $source);

            return $connection->refresh();
        });
    }
}
