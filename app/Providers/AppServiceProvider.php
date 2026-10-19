<?php

namespace App\Providers;

use App\Database\ConnectionLifecycle;
use App\Database\MariaDbConnection;
use App\Database\MySqlConnection;
use App\Services\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new MySqlConnection($pdo, $database, $prefix, $config));
        Connection::resolverFor('mariadb', fn ($pdo, $database, $prefix, $config) => new MariaDbConnection($pdo, $database, $prefix, $config));
        $this->app->scoped(Tenant::class, fn () => new Tenant);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->terminating(fn () => ConnectionLifecycle::release($this->app['db']));
        RateLimiter::for('catalog-pix', fn (Request $r) => Limit::perMinute(3)->by($r->ip().'|'.$r->route('slug').'|'.$r->route('id')));
        RateLimiter::for('catalog-payment-status', fn (Request $r) => Limit::perMinute(12)->by($r->ip().'|'.$r->route('slug').'|'.$r->route('id').'|'.$r->route('reference')));
        Gate::define('manage-company', fn ($user) => $user->active && in_array($user->role, ['admin', 'super']));
        Gate::define('platform', fn ($user) => $user->active && $user->role === 'super');
        Paginator::defaultView('components.pagination');
    }
}
