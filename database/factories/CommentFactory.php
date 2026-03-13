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
            'body' => $this->faker->paragraph(rand(1, 4)),
            'is_approved' => $this->faker->boolean(90),
            'created_at' => $this->faker->dateTimeBetween('-1 year'),
        ];
    }
}
