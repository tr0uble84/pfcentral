<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Moves PFCentral's cache from MySQL to Redis.
 *
 * Older installs keep the cache in MySQL. It is rewritten every few seconds
 * (firewall status), and MySQL records every change in its binary log, which
 * filled a 24 GB disk in about two weeks. The installer has used Redis since
 * v0.20; this moves existing servers over, but only when Redis answers and
 * accepts writes, so a server without a working Redis is left unchanged.
 */
class RedisCacheSwitch
{
    /** @return string what happened, for the update log */
    public static function apply(): string
    {
        $env = app()->environmentFilePath();
        if (! is_file($env) || ! is_writable($env)) {
            return 'skipped: .env is not writable';
        }
        $text = (string) file_get_contents($env);
        $store = self::value($text, 'CACHE_STORE') ?? self::value($text, 'CACHE_DRIVER') ?? 'database';
        if ($store !== 'database') {
            return 'skipped: cache already uses ' . $store;
        }
        if (! class_exists(\Redis::class) && ! class_exists(\Predis\Client::class)) {
            return 'skipped: no Redis client for PHP';
        }
        try {
            $r = Redis::connection(config('cache.stores.redis.connection', 'cache'));
            $r->setex('pfc:cache-switch:probe', 30, '1');
            if ((string) $r->get('pfc:cache-switch:probe') !== '1') {
                return 'skipped: Redis did not return a test value';
            }
            $r->del('pfc:cache-switch:probe');
        } catch (\Throwable $e) {
            return 'skipped: Redis not usable (' . mb_substr($e->getMessage(), 0, 120) . ')';
        }

        foreach (['CACHE_STORE', 'CACHE_DRIVER'] as $key) {
            $text = self::set($text, $key, 'redis', $key === 'CACHE_STORE');
        }
        if (file_put_contents($env, $text) === false) {
            return 'skipped: could not write .env';
        }
        // The updater runs "config:cache" in this same process next. Laravel
        // won't re-read a variable that is already set, so set it here too,
        // or the cached config would keep the old value.
        foreach (['CACHE_STORE', 'CACHE_DRIVER'] as $key) {
            putenv($key . '=redis');
            $_ENV[$key] = $_SERVER[$key] = 'redis';
        }
        // The running process keeps its old config on purpose: the updater's
        // "queue:restart" signal must still reach workers that read the old
        // (MySQL) cache. They restart and come back on Redis.

        // The old rows were only temporary values; free the space.
        foreach (['cache', 'cache_locks'] as $table) {
            try {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            } catch (\Throwable) {
            }
        }
        Log::info('PFCentral: cache moved from MySQL to Redis.');

        return 'moved the cache from MySQL to Redis';
    }

    private static function value(string $text, string $key): ?string
    {
        return preg_match('/^' . $key . '=["\']?([^"\'\r\n#]*)/m', $text, $m) ? trim($m[1]) : null;
    }

    private static function set(string $text, string $key, string $value, bool $addIfMissing): string
    {
        if (preg_match('/^' . $key . '=.*$/m', $text)) {
            return preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $text);
        }

        return $addIfMissing ? rtrim($text, "\n") . "\n" . $key . '=' . $value . "\n" : $text;
    }
}
