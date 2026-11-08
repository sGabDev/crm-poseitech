<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatabaseCapacity
{
    public static function probePath(): string
    {
        return self::path().'.probe';
    }

    // Allow one read-only recovery check per instance and interval. Never
    // replay the request that failed: its write may already have committed.
    public static function recoverIfAvailable(): bool
    {
        if (self::remaining() === 0) {
            return true;
        }
        $handle = @fopen(self::probePath(), 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
            if ((int) stream_get_contents($handle) > time()) {
                return false;
            }
            $until = @file_get_contents(self::path());
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) (time() + max(1, (int) config('database_pool.recheck_seconds', 30))));
            fflush($handle);
            try {
                DB::select('SELECT 1');
            } catch (\Throwable) {
                // Keep the original pause; repeated failures must not extend it.
                return false;
            }
            $capacity = @fopen(self::path(), 'c+');
            if ($capacity === false) {
                return false;
            }
            try {
                if (! flock($capacity, LOCK_EX)) {
                    return false;
                }
                // Do not clear a newer, longer pause set while probing.
                if (stream_get_contents($capacity) !== $until) {
                    return false;
                }
                rewind($capacity);
                ftruncate($capacity, 0);
                fwrite($capacity, '0');
                fflush($capacity);
            } finally {
                fclose($capacity);
            }

            return true;
        } finally {
            fclose($handle);
        }
    }

    public static function path(): string
    {
        return storage_path('framework/db-capacity-'.app()->environment().'.until');
    }

    public static function exhausted(\Throwable $error): bool
    {
        do {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1226, 1203, 1040, 9001], true)) {
                return true;
            }
        } while ($error = $error->getPrevious());

        return false;
    }

    public static function pause(?\Throwable $error = null): void
    {
        // Account quotas may need minutes; a full connection pool usually needs seconds.
        $quota = $error === null;
        while ($error) {
            $quota = $quota || (int) ($error->errorInfo[1] ?? 0) === 1226;
            $error = $error->getPrevious();
        }
        $seconds = max(1, (int) config($quota ? 'database_pool.cooldown' : 'database_pool.saturation_cooldown', $quota ? 300 : 3));
        // Preserve a longer pause and avoid extending it on every concurrent failure.
        $handle = @fopen(self::path(), 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (flock($handle, LOCK_EX)) {
                $until = (int) stream_get_contents($handle);
                $target = time() + $seconds;
                if ($until <= time()) {
                    rewind($handle);
                    ftruncate($handle, 0);
                    fwrite($handle, (string) $target);
                    fflush($handle);
                }
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    public static function remaining(): int
    {
        $handle = @fopen(self::path(), 'r');
        if ($handle === false) {
            return 0;
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                return 0;
            }
            $remaining = max(0, (int) stream_get_contents($handle) - time());
            flock($handle, LOCK_UN);

            return $remaining;
        } finally {
            fclose($handle);
        }
    }

    public static function response(Request $request)
    {
        $seconds = max(1, min(self::remaining(), (int) config('database_pool.recheck_seconds', 30)));
        $response = $request->expectsJson()
            ? response()->json(['status' => 'unavailable', 'qr' => null, 'message' => 'O banco está temporariamente indisponível. As atualizações foram pausadas. Tente novamente mais tarde.', 'retry_after' => $seconds], 503)
            : response()->view('errors.database-capacity', [], 503);

        return $response->header('Retry-After', (string) $seconds)->header('Cache-Control', 'no-store');
    }
}
