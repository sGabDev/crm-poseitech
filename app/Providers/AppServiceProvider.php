<?php

namespace App\Providers;

use App\Services\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
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
        $this->app->scoped(Tenant::class, fn () => new Tenant);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('catalog-pix', fn (Request $r) => Limit::perMinute(3)->by($r->ip().'|'.$r->route('slug').'|'.$r->route('id')));
        RateLimiter::for('catalog-payment-status', fn (Request $r) => Limit::perMinute(12)->by($r->ip().'|'.$r->route('slug').'|'.$r->route('id').'|'.$r->route('reference')));
        Gate::define('manage-company', fn ($user) => $user->active && in_array($user->role, ['admin', 'super']));
        Gate::define('platform', fn ($user) => $user->active && $user->role === 'super');
        Paginator::defaultView('components.pagination');
    }
}
