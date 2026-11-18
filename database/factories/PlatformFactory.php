<?php

namespace Omarsenusi\MarketplacePlatforms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Enums\AuthMode;
use Omarsenusi\MarketplacePlatforms\Enums\PlatformStatus;
use Omarsenusi\MarketplacePlatforms\Models\Platform;

/**
 * @extends Factory<Platform>
 */
class PlatformFactory extends Factory
{
    protected $model = Platform::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Salla App', 'Zid App']);

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'driver' => 'salla',
            'app_id' => null,
            'client_id' => 'client_'.Str::random(12),
            'auth_mode' => AuthMode::Easy,
            'marketplace_app_url' => null,
            'supports_marketplace_subscriptions' => false,
            'status' => PlatformStatus::Draft,
            'config' => [],
        ];
    }
}
