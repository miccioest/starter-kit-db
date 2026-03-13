<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PostFactory extends Factory
{
    public function definition(): array
    {
        $title = $this->faker->sentence(rand(4, 10));

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(6),
            'body' => $this->faker->paragraphs(rand(3, 8), true),
            'status' => $this->faker->randomElement(['draft', 'published', 'published', 'published', 'archived']),
            'views_count' => $this->faker->numberBetween(0, 10000),
            'published_at' => $this->faker->dateTimeBetween('-1 year'),
            'created_at' => $this->faker->dateTimeBetween('-1 year'),
        ];
    }
}
