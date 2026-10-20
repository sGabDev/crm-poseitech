<?php

use App\Http\Middleware\CompanyAccess;
use App\Http\Middleware\DatabaseCapacityCooldown;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RouteThrottleRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\DatabaseCapacity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['integrations/mercadopago/*/webhook']);
        $middleware->prepend(DatabaseCapacityCooldown::class);
        $middleware->alias(['company' => CompanyAccess::class, 'throttle' => RouteThrottleRequests::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [RequirePasswordChange::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['access_token', 'webhook_secret']);
        $exceptions->report(function (PDOException $error) {
            if (DatabaseCapacity::exhausted($error)) {
                DatabaseCapacity::pause($error);
            }
            // QueryException messages embed SQL and bindings. Never report the exception.
            logger()->warning('database.operation_failed', [
                'sqlstate' => preg_match('/^[A-Z0-9]{5}$/', (string) ($error->errorInfo[0] ?? '')) ? $error->errorInfo[0] : 'unknown',
                'driver_code' => (int) ($error->errorInfo[1] ?? 0),
            ]);

            return false;
        });
        $exceptions->render(function (PDOException $error, Request $request) {
            if (DatabaseCapacity::exhausted($error)) {
                DatabaseCapacity::pause($error);

                return DatabaseCapacity::response($request);
            }
        });
    })->create();

if (! is_dir($app->basePath('public')) && is_dir(dirname($app->basePath()).'/public_html')) {
    $app->usePublicPath(dirname($app->basePath()).'/public_html');
}

return $app;
