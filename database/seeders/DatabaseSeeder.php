<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Target: ~100K rows across all tables to ensure queries hit real data.
     * - 500 users
     * - 50 tags
     * - 10,000 posts (with tag pivots)
     * - 50,000 comments
     * - ~40,000 notifications + activity_logs generated as side effects
     */
    public function run(): void
    {
        $this->command->info('Creating users...');
        $users = User::factory(500)->create();

        $this->command->info('Creating tags...');
        $tags = Tag::factory(50)->create();

        $this->command->info('Creating posts with tags...');
        $userIds = $users->pluck('id');
        $tagIds = $tags->pluck('id');

        // Create posts in chunks to avoid memory issues
        collect(range(1, 100))->each(function ($batch) use ($userIds, $tagIds) {
            $posts = Post::factory(100)->create([
                'user_id' => fn () => $userIds->random(),
            ]);

            // Attach 1-4 random tags to each post
            $posts->each(function ($post) use ($tagIds) {
                $post->tags()->attach(
                    $tagIds->random(rand(1, 4))->all()
                );
            });

            if ($batch % 10 === 0) {
                $this->command->info("  Posts: {$batch}00 / 10000");
            }
        });

        $this->command->info('Creating comments...');
        $postIds = Post::pluck('id');

        collect(range(1, 100))->each(function ($batch) use ($postIds, $userIds) {
            Comment::factory(500)->create([
                'post_id' => fn () => $postIds->random(),
                'user_id' => fn () => $userIds->random(),
            ]);

            if ($batch % 10 === 0) {
                $this->command->info("  Comments: ".($batch * 500)." / 50000");
            }
        });

        $this->command->info('Creating notifications...');
        $now = now();
        $notifications = [];

        foreach (range(1, 20000) as $i) {
            $notifications[] = [
                'user_id' => $userIds->random(),
                'type' => fake()->randomElement(['comment', 'like', 'follow', 'mention', 'system']),
                'data' => json_encode(['message' => fake()->sentence()]),
                'is_read' => fake()->boolean(60),
                'created_at' => fake()->dateTimeBetween('-6 months', $now),
                'updated_at' => $now,
            ];

            if (count($notifications) >= 1000) {
                \App\Models\Notification::insert($notifications);
                $notifications = [];
            }

            if ($i % 5000 === 0) {
                $this->command->info("  Notifications: {$i} / 20000");
            }
        }

        $this->command->info('Creating activity logs...');
        $activities = [];

        foreach (range(1, 20000) as $i) {
            $activities[] = [
                'user_id' => $userIds->random(),
                'action' => fake()->randomElement(['created', 'updated', 'deleted', 'viewed', 'exported']),
                'subject_type' => fake()->randomElement(['post', 'comment', 'user', 'tag']),
                'subject_id' => rand(1, 10000),
                'properties' => json_encode(['ip' => fake()->ipv4(), 'agent' => fake()->userAgent()]),
                'created_at' => fake()->dateTimeBetween('-6 months', $now),
                'updated_at' => $now,
            ];

            if (count($activities) >= 1000) {
                \App\Models\ActivityLog::insert($activities);
                $activities = [];
            }

            if ($i % 5000 === 0) {
                $this->command->info("  Activity logs: {$i} / 20000");
            }
        }

        $this->command->info('Seeding complete!');
    }
}
