<?php

namespace Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Workspace;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_user_id' => null,
        ];
    }
}
