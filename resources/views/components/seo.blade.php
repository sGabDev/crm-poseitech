@php($seo=\App\Services\Seo::metadata($company ?? null))
<meta name="description" content="{{ $seo['description'] }}">
<meta name="robots" content="{{ $seo['indexable'] ? 'index, follow, max-image-preview:large' : 'noindex, nofollow, noarchive' }}">
<meta name="theme-color" content="#176b55">
@if($seo['canonical'])<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
<meta property="og:locale" content="pt_BR">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $seo['name'] }}">
<meta property="og:title" content="{{ $seo['title'] ?? trim($__env->yieldContent('title','PoseiTech CRM')) }}">
<meta property="og:description" content="{{ $seo['description'] }}">
@if($seo['canonical'])<meta property="og:url" content="{{ $seo['canonical'] }}">@endif
<meta property="og:image" content="{{ $seo['image'] }}">
<meta property="og:image:alt" content="{{ $seo['name'] }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo['title'] ?? trim($__env->yieldContent('title','PoseiTech CRM')) }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $seo['image'] }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
@if(request()->is('catalog/*') && count(request()->segments())===2 && isset($products,$company))
<script type="application/ld+json" nonce="{{ request()->attributes->get('csp_nonce') }}">{!! json_encode(\App\Services\Seo::structuredData($company,$products),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
@endif
