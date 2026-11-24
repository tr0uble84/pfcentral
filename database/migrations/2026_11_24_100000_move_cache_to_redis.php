<?php

use App\Support\RedisCacheSwitch;
use Illuminate\Database\Migrations\Migration;

/**
 * Moves the cache from MySQL to Redis on servers installed before v0.20
 * (see App\Support\RedisCacheSwitch). Runs once, during the update.
 * It changes nothing when Redis isn't working or the cache is already elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $result = RedisCacheSwitch::apply();
        if (app()->runningInConsole()) {
            fwrite(STDOUT, '  Cache: ' . $result . PHP_EOL);
        }
    }

    public function down(): void
    {
        // Nothing to undo: Redis keeps working as the cache.
    }
};
