<?php

namespace Omarsenusi\MarketplacePlatforms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * @extends Factory<PlatformConnection>
 */
class PlatformConnectionFactory extends Factory
{
    protected $model = PlatformConnection::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            // Host workspace via config (null keeps the connection
            // pending_oauth; most tests attach a fixture workspace).
            'workspace_id' => null,
            'external_store_id' => 'store_'.fake()->unique()->numerify('######'),
            'status' => ConnectionStatus::PendingOAuth,
            'tokens' => null,
            'token_expires_at' => null,
            'refresh_scheduled_at' => null,
            'oauth_completed_at' => null,
            'uninstalled_at' => null,
        ];
    }
}
