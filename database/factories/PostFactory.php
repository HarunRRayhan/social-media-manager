<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\SocialSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_set_id' => SocialSet::factory(),
            'team_id' => fn (array $attributes) => SocialSet::whereKey($attributes['social_set_id'])->value('team_id'),
            'platform' => fake()->randomElement(Platform::cases())->value,
            'body' => fake()->sentence(),
            'media' => null,
            'status' => 'draft',
            'scheduled_at' => null,
            'published_at' => null,
            'error' => null,
        ];
    }
}
