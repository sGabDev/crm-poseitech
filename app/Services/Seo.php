<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Str;

class Seo
{
    public static function url(string $path = ''): string
    {
        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    public static function indexable(?Company $company): bool
    {
        return config('seo.indexing') && $company && $company->available() && $company->enabled('catalog') && ($company->settings['catalog_indexable'] ?? true);
    }

    public static function metadata(?Company $company = null): array
    {
        $catalog = request()->isMethod('GET') && request()->is('catalog/*') && count(request()->segments()) === 2 && $company;
        $personalized = $catalog && request()->hasSession() && request()->session()->has('catalog_profile_'.$company->id);
        $indexable = $catalog && self::indexable($company) && ! $personalized && ! request()->user();
        $name = $catalog ? $company->name : config('seo.name');
        $title = $catalog ? (($company->settings['catalog_seo_title'] ?? '') ?: $company->name.' · Catálogo online') : null;
        $description = $catalog ? (($company->settings['catalog_seo_description'] ?? '') ?: ($company->settings['catalog_description'] ?? '') ?: 'Conheça os produtos de '.$company->name.' e faça seu pedido online.') : config('seo.description');
        $canonical = $catalog ? self::url('catalog/'.$company->slug) : null;
        $image = self::url('assets/share-card.png');
        if ($catalog && ($company->settings['catalog_banner'] ?? null)) {
            $image = self::url('catalog-media/'.$company->id.'/banner');
        } elseif ($catalog && $company->logo) {
            $image = self::url('logo/'.$company->id);
        }

        return ['title' => $title, 'description' => Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($description))), 160, ''), 'name' => $name, 'canonical' => $canonical, 'image' => $image, 'indexable' => $indexable];
    }

    public static function structuredData(Company $company, $products): array
    {
        $url = self::url('catalog/'.$company->slug);
        $store = ['@type' => 'Store', '@id' => $url.'#store', 'name' => $company->name, 'url' => $url, 'description' => self::metadata($company)['description']];
        if ($company->phone ?: $company->whatsapp) {
            $store['telephone'] = $company->phone ?: $company->whatsapp;
        }
        if ($company->address) {
            $store['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $company->address];
        }
        if ($company->logo) {
            $store['image'] = self::url('logo/'.$company->id);
        }
        $offers = [];
        foreach ($products->take(100) as $product) {
            $item = ['@type' => $product->type === 'service' ? 'Service' : 'Product', 'name' => $product->name, 'description' => strip_tags($product->description ?? ''), 'url' => $url.'#product-'.$product->id];
            if ($product->image) {
                $item['image'] = self::url('media/'.$company->id.'/'.$product->id);
            }
            $offers[] = ['@type' => 'Offer', 'price' => number_format($product->price / 100, 2, '.', ''), 'priceCurrency' => $company->currency, 'url' => $item['url'], 'availability' => 'https://schema.org/'.($company->enabled('stock') && $product->type === 'product' && $product->stock <= 0 ? 'OutOfStock' : 'InStock'), 'itemOffered' => $item];
        }
        $store['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'name' => 'Catálogo de '.$company->name, 'itemListElement' => $offers];

        return ['@context' => 'https://schema.org', '@graph' => [$store, ['@type' => 'WebPage', '@id' => $url.'#page', 'url' => $url, 'name' => $company->name.' · Catálogo online', 'inLanguage' => 'pt-BR', 'about' => ['@id' => $url.'#store']]]];
    }
}
