<?php

namespace App\Services;

use Illuminate\Http\Request;

class DatabaseCapacity
{
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

    public static function pause(): void
    {
        @file_put_contents(self::path(), (string) (time() + max(1, (int) config('database_pool.cooldown', 300))), LOCK_EX);
    }

    public static function remaining(): int
    {
        return is_file(self::path()) ? max(0, (int) @file_get_contents(self::path()) - time()) : 0;
    }

    public static function response(Request $request)
    {
        $seconds = max(60, self::remaining());
        $response = $request->expectsJson()
            ? response()->json(['status' => 'unavailable', 'qr' => null, 'message' => 'O banco está temporariamente indisponível. As atualizações foram pausadas. Tente novamente mais tarde.', 'retry_after' => $seconds], 503)
            : response()->view('errors.database-capacity', [], 503);

        return $response->header('Retry-After', (string) $seconds)->header('Cache-Control', 'no-store');
    }
}
