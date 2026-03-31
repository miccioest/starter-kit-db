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
    $debug = [];

    $redisConn = config('cache.stores.redis.connection', 'default');
    $debug['pid'] = getmypid();
    $debug['redis_host'] = config("database.redis.{$redisConn}.host");
    $debug['persistent'] = config("database.redis.{$redisConn}.persistent", false);

    config([
        "database.redis.{$redisConn}.timeout" => 4,
        "database.redis.{$redisConn}.read_timeout" => 4,
    ]);

    // Check if there's an existing connection before disconnect
    try {
        $client = Redis::connection($redisConn)->client();
        $debug['pre_disconnect_connected'] = $client->isConnected();
        if ($client->isConnected()) {
            $debug['pre_disconnect_server'] = $client->getHost() . ':' . $client->getPort();
        }
    } catch (\Throwable $e) {
        $debug['pre_disconnect_error'] = $e->getMessage();
    }

    try { Redis::connection($redisConn)->disconnect(); } catch (\Throwable $e) {
        $debug['disconnect_error'] = $e->getMessage();
    }

    try {
        $t0 = microtime(true);
        $key = 'health-check-' . time();
        Cache::store('redis')->put($key, 'ok', 10);
        $value = Cache::store('redis')->get($key);
        Cache::store('redis')->forget($key);
        $timings['cache_ms'] = round((microtime(true) - $t0) * 1000, 2);

        // Get post-operation connection info
        try {
            $client = Redis::connection($redisConn)->client();
            $debug['post_server'] = $client->getHost() . ':' . $client->getPort();
        } catch (\Throwable $e) {}

        if ($value !== 'ok') {
            Log::warning('Health check: cache read/write verification failed', compact('timings', 'debug'));
            return response()->json(['status' => 'unhealthy', 'failure' => 'cache read/write mismatch', 'timings' => $timings, 'debug' => $debug], 503);
        }

        Log::info('Health check healthy', compact('timings', 'debug'));
        return response()->json(['status' => 'healthy', 'timings' => $timings, 'debug' => $debug], 200);
    } catch (\Exception $e) {
        $timings['cache_ms'] = round((microtime(true) - $t0) * 1000, 2);
        $debug['error_class'] = get_class($e);
        $debug['error_trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);
        Log::warning('Health check unhealthy', compact('timings', 'debug'));
        return response()->json(['status' => 'unhealthy', 'failure' => $e->getMessage(), 'timings' => $timings, 'debug' => $debug], 503);
    }
});
