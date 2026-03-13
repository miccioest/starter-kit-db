<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateLoad extends Command
{
    protected $signature = 'app:generate-load
        {--qps=400 : Target queries per second}
        {--ratio=30 : Read:write ratio (e.g. 30 = 30 reads per 1 write)}
        {--duration=60 : Duration in seconds (0 = run forever)}';

    protected $description = 'Generate realistic MySQL load matching production patterns';

    private int $readCount = 0;
    private int $writeCount = 0;

    public function handle(): int
    {
        $targetQps = (int) $this->option('qps');
        $ratio = (int) $this->option('ratio');
        $duration = (int) $this->option('duration');

        $this->info("Starting load generation: {$targetQps} QPS, {$ratio}:1 read:write ratio, duration: ".($duration ?: 'forever').'s');

        // Pre-fetch IDs to avoid extra queries during the loop
        $userIds = User::pluck('id')->all();
        $postIds = Post::pluck('id')->all();
        $tagIds = Tag::pluck('id')->all();

        if (empty($userIds) || empty($postIds)) {
            $this->error('Database is empty. Run php artisan db:seed first.');
            return 1;
        }

        $startTime = microtime(true);
        $lastReport = $startTime;
        $iterationQueries = 0;

        while (true) {
            $elapsed = microtime(true) - $startTime;

            if ($duration > 0 && $elapsed >= $duration) {
                break;
            }

            // Decide read or write based on ratio
            $doWrite = ($this->readCount >= $ratio * max($this->writeCount, 1));

            if ($doWrite) {
                $iterationQueries += $this->doWriteOperation($userIds, $postIds, $tagIds);
            } else {
                $iterationQueries += $this->doReadOperation($userIds, $postIds, $tagIds);
            }

            // Throttle to stay near target QPS
            $totalQueries = $this->readCount + $this->writeCount;
            $expectedTime = $totalQueries / $targetQps;

            if ($elapsed < $expectedTime) {
                usleep((int) (($expectedTime - $elapsed) * 1_000_000));
            }

            // Report every 10 seconds
            $now = microtime(true);
            if ($now - $lastReport >= 10) {
                $actualQps = $totalQueries / $elapsed;
                $actualRatio = $this->writeCount > 0 ? round($this->readCount / $this->writeCount, 1) : 'inf';
                $this->line(sprintf(
                    '[%.0fs] QPS: %.0f (target: %d) | reads: %d, writes: %d, ratio: %s:1',
                    $elapsed, $actualQps, $targetQps, $this->readCount, $this->writeCount, $actualRatio
                ));
                $lastReport = $now;
            }
        }

        $elapsed = microtime(true) - $startTime;
        $totalQueries = $this->readCount + $this->writeCount;
        $this->info(sprintf(
            'Done. %d queries in %.1fs (%.0f QPS). Reads: %d, Writes: %d',
            $totalQueries, $elapsed, $totalQueries / $elapsed, $this->readCount, $this->writeCount
        ));

        return 0;
    }

    /**
     * Perform a read operation — mirrors typical Laravel app patterns.
     * Each method represents a "page load" with multiple queries.
     */
    private function doReadOperation(array $userIds, array $postIds, array $tagIds): int
    {
        $operation = rand(0, 5);

        return match ($operation) {
            0 => $this->readDashboard($userIds),
            1 => $this->readFeed($postIds),
            2 => $this->readPostDetail($postIds),
            3 => $this->readUserProfile($userIds),
            4 => $this->readTagPosts($tagIds),
            5 => $this->readSearch(),
        };
    }

    /**
     * Dashboard: aggregations + eager loads (typical admin page).
     */
    private function readDashboard(array $userIds): int
    {
        $queries = 0;

        // Total counts
        Post::where('status', 'published')->count();
        $queries++;

        Comment::where('is_approved', true)->count();
        $queries++;

        User::count();
        $queries++;

        // Recent activity with joins
        ActivityLog::with('user')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        $queries += 2;

        // Posts per day (last 7 days)
        Post::where('created_at', '>=', now()->subDays(7))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupByRaw('DATE(created_at)')
            ->get();
        $queries++;

        // Top commenters
        Comment::selectRaw('user_id, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();
        $queries++;

        // Unread notifications for a random user
        Notification::where('user_id', $userIds[array_rand($userIds)])
            ->where('is_read', false)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
        $queries++;

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Feed: paginated posts with eager-loaded relationships.
     */
    private function readFeed(array $postIds): int
    {
        $queries = 0;

        $posts = Post::with(['user', 'tags', 'comments' => fn ($q) => $q->limit(3)])
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->offset(rand(0, 100))
            ->limit(15)
            ->get();
        $queries += 4; // main + 3 eager loads

        // Simulate "has more" check
        Post::where('status', 'published')->count();
        $queries++;

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Post detail: single post with all related data.
     */
    private function readPostDetail(array $postIds): int
    {
        $queries = 0;
        $postId = $postIds[array_rand($postIds)];

        Post::with(['user', 'tags'])->find($postId);
        $queries += 3;

        // Comments with pagination
        Comment::with('user')
            ->where('post_id', $postId)
            ->where('is_approved', true)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        $queries += 2;

        // Related posts (same tags)
        $tagIds = DB::table('post_tag')->where('post_id', $postId)->pluck('tag_id');
        $queries++;

        if ($tagIds->isNotEmpty()) {
            Post::whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds))
                ->where('id', '!=', $postId)
                ->where('status', 'published')
                ->limit(5)
                ->get();
            $queries++;
        }

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * User profile: user data with their posts and stats.
     */
    private function readUserProfile(array $userIds): int
    {
        $queries = 0;
        $userId = $userIds[array_rand($userIds)];

        User::find($userId);
        $queries++;

        Post::where('user_id', $userId)
            ->where('status', 'published')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
        $queries++;

        // Stats
        Post::where('user_id', $userId)->count();
        $queries++;

        Comment::where('user_id', $userId)->count();
        $queries++;

        Post::where('user_id', $userId)->sum('views_count');
        $queries++;

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Tag posts: filtered listing.
     */
    private function readTagPosts(array $tagIds): int
    {
        $queries = 0;
        $tagId = $tagIds[array_rand($tagIds)];

        Tag::find($tagId);
        $queries++;

        Post::whereHas('tags', fn ($q) => $q->where('tags.id', $tagId))
            ->with('user')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(15)
            ->get();
        $queries += 2;

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Search: LIKE queries on text columns (common and expensive).
     */
    private function readSearch(): int
    {
        $queries = 0;
        $term = Str::random(rand(3, 6));

        Post::where('title', 'like', "%{$term}%")
            ->orWhere('body', 'like', "%{$term}%")
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(15)
            ->get();
        $queries++;

        User::where('name', 'like', "%{$term}%")->limit(5)->get();
        $queries++;

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Write operations — create/update records like a real app would.
     */
    private function doWriteOperation(array $userIds, array $postIds, array $tagIds): int
    {
        $operation = rand(0, 4);

        return match ($operation) {
            0 => $this->writeNewPost($userIds, $tagIds),
            1 => $this->writeNewComment($userIds, $postIds),
            2 => $this->writeUpdatePost($postIds),
            3 => $this->writeNotification($userIds),
            4 => $this->writeActivityLog($userIds, $postIds),
        };
    }

    private function writeNewPost(array $userIds, array $tagIds): int
    {
        $queries = 0;

        $post = Post::create([
            'user_id' => $userIds[array_rand($userIds)],
            'title' => fake()->sentence(rand(4, 10)),
            'slug' => Str::slug(fake()->sentence()).'-'.Str::random(6),
            'body' => fake()->paragraphs(rand(2, 5), true),
            'status' => 'published',
            'views_count' => 0,
            'published_at' => now(),
        ]);
        $queries++;

        // Attach tags
        $attachTags = array_map(
            fn () => $tagIds[array_rand($tagIds)],
            range(1, rand(1, 4))
        );
        $post->tags()->attach(array_unique($attachTags));
        $queries++;

        $this->writeCount += $queries;
        return $queries;
    }

    private function writeNewComment(array $userIds, array $postIds): int
    {
        $queries = 0;

        Comment::create([
            'post_id' => $postIds[array_rand($postIds)],
            'user_id' => $userIds[array_rand($userIds)],
            'body' => fake()->paragraph(rand(1, 3)),
            'is_approved' => true,
        ]);
        $queries++;

        $this->writeCount += $queries;
        return $queries;
    }

    private function writeUpdatePost(array $postIds): int
    {
        $queries = 0;

        Post::where('id', $postIds[array_rand($postIds)])
            ->update([
                'views_count' => DB::raw('views_count + '.rand(1, 10)),
                'updated_at' => now(),
            ]);
        $queries++;

        $this->writeCount += $queries;
        return $queries;
    }

    private function writeNotification(array $userIds): int
    {
        $queries = 0;

        Notification::create([
            'user_id' => $userIds[array_rand($userIds)],
            'type' => fake()->randomElement(['comment', 'like', 'follow', 'mention']),
            'data' => json_encode(['message' => fake()->sentence()]),
            'is_read' => false,
        ]);
        $queries++;

        // Also mark some as read (batch update)
        Notification::where('user_id', $userIds[array_rand($userIds)])
            ->where('is_read', false)
            ->limit(5)
            ->update(['is_read' => true]);
        $queries++;

        $this->writeCount += $queries;
        return $queries;
    }

    private function writeActivityLog(array $userIds, array $postIds): int
    {
        $queries = 0;

        ActivityLog::create([
            'user_id' => $userIds[array_rand($userIds)],
            'action' => fake()->randomElement(['created', 'updated', 'viewed']),
            'subject_type' => 'post',
            'subject_id' => $postIds[array_rand($postIds)],
            'properties' => ['ip' => fake()->ipv4()],
        ]);
        $queries++;

        // Cleanup old logs (typical maintenance pattern)
        ActivityLog::where('created_at', '<', now()->subMonths(6))
            ->limit(10)
            ->delete();
        $queries++;

        $this->writeCount += $queries;
        return $queries;
    }
}
