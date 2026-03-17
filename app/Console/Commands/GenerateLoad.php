<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateLoad extends Command
{
    protected \Faker\Generator $fake;
    protected $signature = 'app:generate-load
        {--qps=400 : Target queries per second}
        {--ratio=30 : Read:write ratio (e.g. 30 = 30 reads per 1 write)}
        {--duration=60 : Duration in seconds (0 = run forever)}';

    protected $description = 'Generate realistic MySQL load matching production patterns';

    private int $readCount = 0;
    private int $writeCount = 0;

    // Pre-cached ID ranges for indexed lookups
    private int $maxPostId = 0;
    private int $maxCommentId = 0;
    private int $maxNotificationId = 0;
    private int $maxActivityLogId = 0;

    public function handle(): int
    {
        $this->fake = Faker::create();
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

        // Cache max IDs for range-based indexed lookups
        $this->maxPostId = Post::max('id') ?? 0;
        $this->maxCommentId = Comment::max('id') ?? 0;
        $this->maxNotificationId = Notification::max('id') ?? 0;
        $this->maxActivityLogId = ActivityLog::max('id') ?? 0;

        $startTime = microtime(true);
        $lastReport = $startTime;

        while (true) {
            $elapsed = microtime(true) - $startTime;

            if ($duration > 0 && $elapsed >= $duration) {
                break;
            }

            // Decide read or write based on ratio
            $doWrite = ($this->readCount >= $ratio * max($this->writeCount, 1));

            if ($doWrite) {
                $this->doWriteOperation($userIds, $postIds, $tagIds);
            } else {
                $this->doReadOperation($userIds, $postIds, $tagIds);
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
     * Perform a read operation using indexed queries only.
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
            5 => $this->readNotifications($userIds),
        };
    }

    /**
     * Dashboard: indexed lookups and scoped aggregations.
     */
    private function readDashboard(array $userIds): int
    {
        $queries = 0;
        $userId = $userIds[array_rand($userIds)];

        // Count posts by user (uses user_id index)
        Post::where('user_id', $userId)->count();
        $queries++;

        // Count comments by user (uses user_id index)
        Comment::where('user_id', $userId)->count();
        $queries++;

        // Recent activity for user (uses [user_id, created_at] index)
        ActivityLog::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        $queries++;

        // Unread notifications for user (uses [user_id, is_read] index)
        Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
        $queries++;

        // Recent posts by status+date (uses [status, published_at] index)
        Post::where('status', 'published')
            ->where('published_at', '>=', now()->subDays(7))
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();
        $queries++;

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Feed: paginated posts using composite index.
     */
    private function readFeed(array $postIds): int
    {
        $queries = 0;

        // Uses [status, published_at] index for ordering + filtering
        $posts = Post::with(['user', 'tags'])
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(15)
            ->get();
        $queries += 3; // main + 2 eager loads

        // Load comments for these posts (uses [post_id, is_approved] index)
        if ($posts->isNotEmpty()) {
            Comment::where('post_id', $posts->first()->id)
                ->where('is_approved', true)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();
            $queries++;
        }

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Post detail: PK lookups + indexed comment fetch.
     */
    private function readPostDetail(array $postIds): int
    {
        $queries = 0;
        $postId = $postIds[array_rand($postIds)];

        // PK lookup with eager loads
        Post::with(['user', 'tags'])->find($postId);
        $queries += 3;

        // Comments for post (uses [post_id, is_approved] index)
        Comment::where('post_id', $postId)
            ->where('is_approved', true)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        $queries++;

        // Tags for post (uses post_tag PK)
        DB::table('post_tag')->where('post_id', $postId)->pluck('tag_id');
        $queries++;

        // Another random post by PK
        Post::find($postIds[array_rand($postIds)]);
        $queries++;

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * User profile: all queries use user_id indexes.
     */
    private function readUserProfile(array $userIds): int
    {
        $queries = 0;
        $userId = $userIds[array_rand($userIds)];

        // PK lookup
        User::find($userId);
        $queries++;

        // User's posts (uses user_id index)
        Post::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
        $queries++;

        // User's post count (uses user_id index)
        Post::where('user_id', $userId)->count();
        $queries++;

        // User's comment count (uses user_id index)
        Comment::where('user_id', $userId)->count();
        $queries++;

        // User's total views (uses user_id index)
        Post::where('user_id', $userId)->sum('views_count');
        $queries++;

        // User's activity (uses [user_id, created_at] index)
        ActivityLog::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
        $queries++;

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Tag posts: pivot table join instead of whereHas subquery.
     */
    private function readTagPosts(array $tagIds): int
    {
        $queries = 0;
        $tagId = $tagIds[array_rand($tagIds)];

        // PK lookup
        Tag::find($tagId);
        $queries++;

        // Get post IDs from pivot (uses post_tag PK [post_id, tag_id])
        $tagPostIds = DB::table('post_tag')
            ->where('tag_id', $tagId)
            ->limit(15)
            ->pluck('post_id');
        $queries++;

        // Fetch posts by PK list (uses PK index)
        if ($tagPostIds->isNotEmpty()) {
            Post::with('user')
                ->whereIn('id', $tagPostIds)
                ->where('status', 'published')
                ->get();
            $queries += 2;
        }

        $this->readCount += $queries;
        return $queries;
    }

    /**
     * Notifications: all queries use [user_id, is_read] index.
     * Replaces the old readSearch() which used LIKE '%term%' full scans.
     */
    private function readNotifications(array $userIds): int
    {
        $queries = 0;
        $userId = $userIds[array_rand($userIds)];

        // Unread count (uses [user_id, is_read] index)
        Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();
        $queries++;

        // Recent notifications (uses [user_id, is_read] index)
        Notification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        $queries++;

        // Read notifications (uses [user_id, is_read] index)
        Notification::where('user_id', $userId)
            ->where('is_read', true)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
        $queries++;

        // Activity log for user (uses [user_id, created_at] index)
        ActivityLog::where('user_id', $userId)
            ->where('action', 'viewed')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
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
            'title' => $this->fake->sentence(rand(4, 10)),
            'slug' => Str::slug($this->fake->sentence()).'-'.Str::random(6),
            'body' => $this->fake->paragraphs(rand(2, 5), true),
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
            'body' => $this->fake->paragraph(rand(1, 3)),
            'is_approved' => true,
        ]);
        $queries++;

        $this->writeCount += $queries;
        return $queries;
    }

    private function writeUpdatePost(array $postIds): int
    {
        $queries = 0;

        // PK update (uses PK index)
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
            'type' => $this->fake->randomElement(['comment', 'like', 'follow', 'mention']),
            'data' => json_encode(['message' => $this->fake->sentence()]),
            'is_read' => false,
        ]);
        $queries++;

        // Mark some as read (uses [user_id, is_read] index)
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
            'action' => $this->fake->randomElement(['created', 'updated', 'viewed']),
            'subject_type' => 'post',
            'subject_id' => $postIds[array_rand($postIds)],
            'properties' => ['ip' => $this->fake->ipv4()],
        ]);
        $queries++;

        $this->writeCount += $queries;
        return $queries;
    }
}
