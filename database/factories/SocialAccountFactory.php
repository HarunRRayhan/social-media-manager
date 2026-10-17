<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider' => 'facebook',
            'external_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'name' => fake()->company(),
            'access_token' => 'token-'.fake()->uuid(),
        ];
    }

    public function instagram(): static
    {
        return $this->state(['provider' => 'instagram', 'meta' => ['page_id' => '999']]);
    }
}
