<?php

use Illuminate\Support\Facades\Schedule;

// Run load generation every minute (each run lasts 55 seconds to avoid overlap)
Schedule::command('app:generate-load --duration=55')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/load-generator.log'));
