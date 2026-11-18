<?php

namespace Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => null,
            'phone' => null,
            'locale' => 'ar',
            'email_verified_at' => null,
            'default_workspace_id' => null,
        ];
    }
}
