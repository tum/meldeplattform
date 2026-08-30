<?php

namespace Tests\Feature;

use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('TUM SafeSignal', false);
    }

    public function test_topic_summary_renders_markdown(): void
    {
        $topic = Topic::create([
            'name_de' => 'Markdown-Thema',
            'name_en' => 'Markdown Topic',
            'summary_de' => 'Bitte {red}**dringend**{/red} melden.',
            'summary_en' => 'Please report {red}**urgently**{/red}.',
        ]);

        // Markdown and brand colour shortcodes in the summary must render as
        // sanitised HTML, not raw markers, on both the topic listing and the
        // topic's form page.
        $this->get('/')
            ->assertOk()
            ->assertSee('<strong>', false)
            ->assertSee('class="t-color-red"', false)
            ->assertDontSee('{red}', false)
            ->assertDontSee('**dringend**', false)
            ->assertDontSee('**urgently**', false);

        $this->get("/form/{$topic->id}")
            ->assertOk()
            ->assertSee('<strong>', false)
            ->assertSee('class="t-color-red"', false);
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
        $this->post('/setLang', ['lang' => 'de'])
            ->assertRedirect('/')
            ->assertCookie('lang', 'de');
    }

    public function test_set_lang_rejects_unknown_lang(): void
    {
        $this->post('/setLang', ['lang' => 'zzz'])
            ->assertRedirect('/')
            ->assertCookie('lang', 'en');
    }

    public function test_set_lang_returns_to_same_host_referer(): void
    {
        // The lang switch must bounce the user back to their starting page
        // (so deep links survive the language change) without becoming an
        // open-redirect oracle.
        $this->post('/setLang', ['lang' => 'de'], ['referer' => 'http://localhost/imprint'])
            ->assertRedirect('/imprint')
            ->assertCookie('lang', 'de');

        $this->post('/setLang', ['lang' => 'de'], ['referer' => 'https://evil.example.com/phish'])
            ->assertRedirect('/')
            ->assertCookie('lang', 'de');

        $this->post('/setLang', ['lang' => 'de'], ['referer' => 'http://localhost/setLang?lang=en'])
            ->assertRedirect('/')
            ->assertCookie('lang', 'de');
    }

    public function test_set_lang_refuses_a_protocol_relative_referer_path(): void
    {
        // `http://localhost//evil.com` parses as this host with the path
        // `//evil.com`; url()->to() would emit that verbatim as a
        // protocol-relative URL, turning the same-origin check into an open
        // redirect off-site.
        foreach (['http://localhost//evil.com', 'http://localhost//evil.com/x'] as $referer) {
            $this->post('/setLang', ['lang' => 'de'], ['referer' => $referer])
                ->assertRedirect('/');
        }
    }

    public function test_health_endpoint_is_ok(): void
    {
        $this->get('/up')->assertOk();
    }
}
