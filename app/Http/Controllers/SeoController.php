<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Seo;
use Illuminate\Support\Facades\Cache;

class SeoController extends Controller
{
    private function remember(string $key, callable $callback)
    {
        return app()->runningUnitTests() ? $callback() : Cache::store('file')->remember($key, 300, $callback);
    }

    private function companies()
    {
        return Company::where('status', 'active')->where(fn ($q) => $q->whereNull('subscription_until')->orWhereDate('subscription_until', '>=', today()->toDateString()))->whereJsonContains('modules', 'catalog')->where(fn ($q) => $q->whereNull('settings->catalog_indexable')->orWhere('settings->catalog_indexable', true));
    }

    private function xml(string $body)
    {
        return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".$body, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'X-Robots-Tag' => 'noindex']);
    }

    public function sitemap()
    {
        $key = 'seo.sitemap.v1.'.hash('sha256', config('app.url').(int) config('seo.indexing'));
        $body = $this->remember($key, function () {
            $count = config('seo.indexing') ? $this->companies()->count() : 0;
            $xml = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            for ($page = 1; $page <= max(1, (int) ceil($count / 1000)); $page++) {
                $xml .= '<sitemap><loc>'.htmlspecialchars(Seo::url('sitemaps/catalogs-'.$page.'.xml'), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></sitemap>';
            }

            return $xml.'</sitemapindex>';
        });

        return $this->xml($body);
    }

    public function catalogs(int $page)
    {
        abort_unless($page > 0 && $page <= 10000, 404);
        $total = $this->remember('seo.count.v1.'.hash('sha256', config('app.url').(int) config('seo.indexing')), fn () => config('seo.indexing') ? $this->companies()->count() : 0);
        abort_unless($page <= max(1, (int) ceil($total / 1000)), 404);
        $key = 'seo.catalogs.v1.'.hash('sha256', config('app.url').(int) config('seo.indexing')).'.'.$page;
        $body = $this->remember($key, function () use ($page) {
            $xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            $companies = config('seo.indexing') ? $this->companies()->orderBy('id')->offset(($page - 1) * 1000)->limit(1000)->get(['id', 'slug']) : collect();
            foreach ($companies as $company) {
                $xml .= '<url><loc>'.htmlspecialchars(Seo::url('catalog/'.$company->slug), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
            }

            return $xml.'</urlset>';
        });

        return $this->xml($body);
    }

    public function robots()
    {
        $rules = config('seo.indexing')
            ? "User-agent: *\nAllow: /catalog/\nDisallow: /catalog/*/orders/\nDisallow: /pay/\nDisallow: /receipt/\nDisallow: /portal\nDisallow: /admin\nDisallow: /settings\nDisallow: /support\nDisallow: /integrations/\nDisallow: /up\n"
            : "User-agent: *\nDisallow: /\n";

        return response($rules.'Sitemap: '.Seo::url('sitemap.xml')."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }
}
