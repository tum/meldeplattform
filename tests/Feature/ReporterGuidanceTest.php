<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporter-facing guidance added after the Diversity & Inclusion / Gender
 * Equality feedback: process and support pages, crawlable German content,
 * and a visible acknowledgement status for reporters.
 */
class ReporterGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_page_renders_steps_with_configured_deadlines(): void
    {
        config([
            'meldeplattform.acknowledgement_deadline_days' => 7,
            'meldeplattform.feedback_deadline_days' => 90,
        ]);

        $this->withCookie('lang', 'de')->get('/process')
            ->assertOk()
            ->assertSee('Eingangsbestätigung innerhalb von 7 Tagen')
            ->assertSee('Rückmeldung innerhalb von 90 Tagen')
            ->assertSee('Was erfährt die gemeldete Person?');

        $this->withCookie('lang', 'en')->get('/process')
            ->assertOk()
            ->assertSee('Acknowledgement within 7 days')
            ->assertSee('What does the reported person learn?');
    }

    public function test_support_page_lists_contact_points_in_both_languages(): void
    {
        $this->withCookie('lang', 'de')->get('/support')
            ->assertOk()
            ->assertSee('Beratung &amp; weitere Meldewege', false)
            ->assertSee('mailto:diversity@tum.de', false)
            ->assertSee('116 016');

        $this->withCookie('lang', 'en')->get('/support')
            ->assertOk()
            ->assertSee('Support at TUM');
    }

    public function test_home_page_names_concrete_concerns_and_links_guidance(): void
    {
        $this->withCookie('lang', 'de')->get('/')
            ->assertOk()
            ->assertSee('sexuelle Belästigung')
            ->assertSee('Meldeplattform für Fehlverhalten, Belästigung &amp; IT-Sicherheit', false)
            ->assertDontSee('Whistleblowing')
            ->assertSee(route('process'), false)
            ->assertSee(route('support'), false);
    }

    public function test_lang_query_parameter_overrides_header_and_cookie_without_persisting(): void
    {
        // Crawlers send no Accept-Language; ?lang=de must still yield the
        // German page so its search terms get indexed.
        $response = $this->withCookie('lang', 'en')
            ->get('/?lang=de', ['Accept-Language' => 'en-US'])
            ->assertOk()
            ->assertSee('<html lang="de">', false)
            ->assertHeader('Content-Language', 'de-DE');
        $this->assertNull($response->getCookie('lang', false));

        $this->get('/?lang=xx', ['Accept-Language' => 'en-US'])
            ->assertSee('<html lang="en">', false);
    }

    public function test_layout_announces_both_language_versions(): void
    {
        $this->get('/')
            ->assertSee('hreflang="de" href="http://localhost?lang=de"', false)
            ->assertSee('hreflang="en" href="http://localhost?lang=en"', false)
            ->assertSee('<meta name="description" content="TUM SafeSignal:', false);
    }

    public function test_reporter_sees_acknowledgement_status_but_not_internal_state(): void
    {
        $report = Report::factory()->create(['state' => ReportState::Spam]);
        $url = route('report.show', ['reporterToken' => $report->reporter_token]);

        $this->withCookie('lang', 'de')->get($url)
            ->assertOk()
            ->assertSee('Eingang noch nicht bestätigt')
            ->assertDontSee('Spam');

        $report->acknowledge();

        $this->withCookie('lang', 'de')->get($url)
            ->assertSee('Eingang bestätigt am '.now()->format('d.m.Y'));
    }

    public function test_form_page_links_to_process_and_support(): void
    {
        $report = Report::factory()->create();

        $this->get('/form/'.$report->topic_id)
            ->assertOk()
            ->assertSee(route('process'), false)
            ->assertSee(route('support'), false);
    }
}
