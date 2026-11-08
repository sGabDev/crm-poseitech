<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_legal_pages_are_public_and_do_not_load_third_party_trackers(): void
    {
        foreach (['privacidade', 'termos', 'cookies', 'reembolso'] as $page) {
            $response = $this->get('/legal/'.$page)->assertOk()
                ->assertSee('suporte@poseitech.com.br')
                ->assertSee('data-privacy-open', false)
                ->assertSee('assets/privacy.js', false)
                ->assertDontSee('164.453.887-36');
            $this->assertStringNotContainsString('googletagmanager.com', $response->getContent());
            $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        }
        $this->get('/legal/desconhecido')->assertNotFound();
    }

    public function test_refund_policy_preserves_withdrawal_rights(): void
    {
        $this->get('/legal/reembolso')->assertOk()->assertSee('7 dias')->assertSee('art. 49')->assertSee('motivo é opcional');
    }

    public function test_legal_identity_is_escaped(): void
    {
        config(['legal.name' => '<script>alert(1)</script>', 'legal.address' => 'Endereço de teste']);
        $this->get('/legal/termos')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('Endereço de teste');
    }
}
