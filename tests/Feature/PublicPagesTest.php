<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Reporting Platform', false);
    }

    public function test_imprint_renders(): void
    {
        $this->get('/imprint')->assertOk();
    }

    public function test_privacy_renders(): void
    {
        $this->get('/privacy')->assertOk();
    }

    public function test_set_lang_persists_cookie(): void
    {
        $this->get('/setLang?lang=de')
            ->assertRedirect('/')
            ->assertCookie('lang', 'de');
    }

    public function test_set_lang_rejects_unknown_lang(): void
    {
        $this->get('/setLang?lang=zzz')
            ->assertRedirect('/')
            ->assertCookie('lang', 'en');
    }

    public function test_health_endpoint_is_ok(): void
    {
        $this->get('/up')->assertOk();
    }
}
