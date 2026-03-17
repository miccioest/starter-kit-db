<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FillDatabase extends Command
{
    protected $signature = 'app:fill-database
        {--target=200 : Target database size in MB}
        {--batch=500 : Rows per INSERT batch}';

    protected $description = 'Bulk-insert data until the database reaches a target size in MB';

    public function handle(): int
    {
        $targetMb = (int) $this->option('target');
        $batchSize = (int) $this->option('batch');

        $currentMb = $this->getDatabaseSizeMb();
        $this->info("Current DB size: {$currentMb} MB, target: {$targetMb} MB");

        if ($currentMb >= $targetMb) {
            $this->info('Database already at or above target size.');
            return 0;
        }

        // Pre-fetch existing IDs for foreign keys
        $userIds = DB::table('users')->pluck('id')->all();
        $postIds = DB::table('posts')->pluck('id')->all();

        if (empty($userIds) || empty($postIds)) {
            $this->error('Database needs base seed data first. Run php artisan db:seed.');
            return 1;
        }

        $round = 0;
        while (($currentMb = $this->getDatabaseSizeMb()) < $targetMb) {
            $round++;
            $remaining = $targetMb - $currentMb;
            $this->line("[Round {$round}] {$currentMb} MB / {$targetMb} MB ({$remaining} MB remaining)");

            // Insert comments (~1KB each with body text) — most efficient filler
            // 500 rows × ~1KB ≈ 0.5MB per batch (plus indexes)
            $rows = [];
            $now = now()->toDateTimeString();

            for ($i = 0; $i < $batchSize; $i++) {
                $rows[] = [
                    'post_id' => $postIds[array_rand($postIds)],
                    'user_id' => $userIds[array_rand($userIds)],
                    'body' => Str::random(800) . ' ' . Str::random(200),
                    'is_approved' => rand(0, 1),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('comments')->insert($rows);

            // Also bulk-insert posts every 3rd round (to keep table ratios somewhat realistic)
            if ($round % 3 === 0) {
                $postRows = [];
                for ($i = 0; $i < 100; $i++) {
                    $postRows[] = [
                        'user_id' => $userIds[array_rand($userIds)],
                        'title' => Str::random(40) . ' ' . Str::random(20),
                        'slug' => Str::random(30) . '-' . Str::random(8),
                        'body' => Str::random(2000) . ' ' . Str::random(2000),
                        'status' => 'published',
                        'views_count' => rand(0, 5000),
                        'published_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('posts')->insert($postRows);
                // Update postIds cache
                $newIds = DB::table('posts')->orderByDesc('id')->limit(100)->pluck('id')->all();
                $postIds = array_merge($postIds, $newIds);
            }
        }

        $finalMb = $this->getDatabaseSizeMb();
        $this->info("Done. Final DB size: {$finalMb} MB");

        return 0;
    }

    private function getDatabaseSizeMb(): float
    {
        $result = DB::selectOne("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS size_mb
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
        ");

        return (float) ($result->size_mb ?? 0);
    }
}
