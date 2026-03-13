<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(rand(1, 4)),
            'is_approved' => fake()->boolean(90),
            'created_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
