<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/status', function () {
    return response()->json([
        'status' => 'ok',
        'database' => DB::connection()->getDatabaseName(),
        'counts' => [
            'users' => User::count(),
            'posts' => Post::count(),
            'comments' => Comment::count(),
        ],
    ]);
});

Route::get('/health', function () {
    $timings = [];

    $redisConn = config('cache.stores.redis.connection', 'default');
    config([
        "database.redis.{$redisConn}.timeout" => 4,
        "database.redis.{$redisConn}.read_timeout" => 4,
    ]);
    try { Redis::connection($redisConn)->disconnect(); } catch (\Throwable $e) {}

    try {
        $t0 = microtime(true);
        $key = 'health-check-' . time();
        Cache::store('redis')->put($key, 'ok', 10);
        $value = Cache::store('redis')->get($key);
        Cache::store('redis')->forget($key);
        $timings['cache_ms'] = round((microtime(true) - $t0) * 1000, 2);

        if ($value !== 'ok') {
            Log::warning('Health check: cache read/write verification failed', $timings);
            return response()->json(['status' => 'unhealthy', 'failure' => 'cache read/write mismatch', 'timings' => $timings], 503);
        }

        Log::info('Health check healthy', $timings);
        return response()->json(['status' => 'healthy', 'timings' => $timings], 200);
    } catch (\Exception $e) {
        $timings['cache_ms'] = round((microtime(true) - $t0) * 1000, 2);
        Log::warning('Health check unhealthy', ['error' => $e->getMessage(), 'timings' => $timings]);
        return response()->json(['status' => 'unhealthy', 'failure' => $e->getMessage(), 'timings' => $timings], 503);
    }
});
