<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\User;
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
            'user_id' => User::factory(),
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
