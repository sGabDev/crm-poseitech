<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Services\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SeoProductionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['app.url' => 'https://crm.example.test', 'seo.indexing' => true, 'app.debug' => false]);
        $this->company = Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'), 'name' => 'Loja A & B', 'slug' => 'loja-seo', 'currency' => 'BRL', 'modules' => array_keys(config('poseitech.modules')), 'settings' => ['catalog_description' => 'Produtos selecionados para você.']]);
        $tenant = app(Tenant::class);
        $tenant->company = $this->company;
        $tenant->insert('products', ['name' => 'Produto <especial>', 'description' => 'Descrição do produto', 'price' => 2500, 'cost' => 100, 'stock' => 10, 'active' => true]);
    }

    public function test_public_catalog_has_canonical_social_metadata_and_safe_structured_data(): void
    {
        $response = $this->get('/catalog/loja-seo?utm_source=test')->assertOk()->assertSee('name="robots" content="index, follow, max-image-preview:large"', false)->assertSee('rel="canonical" href="https://crm.example.test/catalog/loja-seo"', false)->assertSee('property="og:image"', false)->assertSee('name="twitter:card"', false)->assertSee('application/ld+json')->assertSee('Pular para o conteúdo');
        $this->assertFalse($response->headers->has('X-Robots-Tag'));
        preg_match('~<script type="application/ld\+json" nonce="([^"]*)">(.*?)</script>~s', $response->getContent(), $match);
        $data = json_decode($match[2], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Loja A & B', $data['@graph'][0]['name']);
        $this->assertSame('25.00', $data['@graph'][0]['hasOfferCatalog']['itemListElement'][0]['price']);
        $this->assertStringContainsString("'nonce-".$match[1]."'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('utm_source', $data['@graph'][1]['url']);
    }

    public function test_sitemap_lists_only_eligible_catalogs_and_is_stateless(): void
    {
        foreach ([['slug' => 'bloqueada', 'status' => 'blocked'], ['slug' => 'sem-catalogo', 'modules' => []], ['slug' => 'opt-out', 'settings' => ['catalog_indexable' => false]], ['slug' => 'expirada', 'subscription_until' => today()->subDay()]] as $extra) {
            Company::create($extra + ['plan_id' => $this->company->plan_id, 'name' => 'Outra empresa', 'modules' => ['catalog']]);
        }
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertSee('https://crm.example.test/sitemaps/catalogs-1.xml', false);
        $response = $this->get('/sitemaps/catalogs-1.xml')->assertOk()->assertSee('https://crm.example.test/catalog/loja-seo', false)->assertDontSee('bloqueada')->assertDontSee('sem-catalogo')->assertDontSee('opt-out')->assertDontSee('expirada')->assertDontSee('/login')->assertDontSee('/pay/');
        $this->assertCount(0, $response->headers->getCookies());
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertCount(1, $xml->url);
    }

    public function test_staging_and_company_opt_out_disable_indexing(): void
    {
        config(['seo.indexing' => false]);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /', false)->assertSee('Sitemap: https://crm.example.test/sitemap.xml', false);
        $this->get('/sitemaps/catalogs-1.xml')->assertOk()->assertDontSee('/catalog/loja-seo');
        $this->get('/catalog/loja-seo')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        config(['seo.indexing' => true]);
        $this->company->update(['settings' => ['catalog_indexable' => false]]);
        $this->get('/catalog/loja-seo')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_auth_pages_and_personalized_catalog_are_not_indexed(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertSee('noindex, nofollow, noarchive');
        $this->withSession(['catalog_profile_'.$this->company->id => ['name' => 'Nome privado']])->get('/catalog/loja-seo')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_custom_404_and_500_preserve_status_and_hide_exception_details(): void
    {
        $this->get('/pagina-inexistente')->assertNotFound()->assertSee('Página não encontrada')->assertSee('noindex,nofollow,noarchive')->assertDontSee('Stack trace');
        Route::get('/_test-seo-error', function () {
            throw new \RuntimeException('PRIVATE_DATABASE_PASSWORD');
        });
        $this->get('/_test-seo-error')->assertStatus(500)->assertSee('Não foi possível carregar esta página')->assertDontSee('PRIVATE_DATABASE_PASSWORD');
    }

    public function test_brand_assets_exist_and_have_expected_dimensions(): void
    {
        foreach (['favicon-32.png' => [32, 32], 'apple-touch-icon.png' => [180, 180], 'icon-192.png' => [192, 192], 'icon-512.png' => [512, 512], 'assets/share-card.png' => [1200, 630]] as $file => $size) {
            $image = getimagesize(public_path($file));
            $this->assertSame($size, [$image[0], $image[1]]);
        }
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pt-BR', $manifest['lang']);
    }

    public function test_custom_error_pages_preserve_all_statuses_and_json_clients_still_receive_json(): void
    {
        foreach ([403, 405, 419, 422, 429, 502, 503, 504] as $code) {
            Route::get('/_test-http-'.$code, fn () => abort($code));
            $this->get('/_test-http-'.$code)->assertStatus($code)->assertSee('error-card')->assertSee('noindex,nofollow,noarchive');
        }
        $this->getJson('/missing-json-endpoint')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }

    public function test_production_check_reports_configuration_without_printing_secrets(): void
    {
        config(['app.debug' => true, 'app.key' => 'secret-never-print', 'session.secure' => false]);
        $this->artisan('poseitech:production-check')->expectsOutput('[PENDENTE] APP_DEBUG=false')->expectsOutput('[PENDENTE] Cookie de sessão seguro')->assertExitCode(1);
    }
}
