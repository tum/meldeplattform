<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Report;
use App\Models\Topic;
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

    public function test_lang_query_parameter_overrides_header_and_cookie_and_persists(): void
    {
        // Crawlers send no Accept-Language; ?lang=de must still yield the
        // German page so its search terms get indexed — and a visitor from a
        // search result keeps German on the next click.
        $this->withCookie('lang', 'en')
            ->get('/?lang=de', ['Accept-Language' => 'en-US'])
            ->assertOk()
            ->assertSee('<html lang="de">', false)
            ->assertHeader('Content-Language', 'de-DE')
            ->assertCookie('lang', 'de');

        $response = $this->get('/?lang=xx', ['Accept-Language' => 'en-US'])
            ->assertSee('<html lang="en">', false);
        $this->assertNull($response->getCookie('lang', false));
    }

    public function test_lang_toggle_drops_a_lang_query_from_the_return_url(): void
    {
        // Otherwise the ?lang=de the user arrived with outranks the new
        // cookie and the toggle appears to do nothing.
        $this->post('/setLang', ['lang' => 'en'], ['referer' => 'http://localhost/?lang=de'])
            ->assertRedirect('/')
            ->assertCookie('lang', 'en');

        $this->post('/setLang', ['lang' => 'en'], ['referer' => 'http://localhost/track?lang=de&x=1'])
            ->assertRedirect('/track?x=1');
    }

    public function test_layout_announces_both_language_versions(): void
    {
        $this->get('/')
            ->assertSee('hreflang="de" href="http://localhost?lang=de"', false)
            ->assertSee('hreflang="en" href="http://localhost?lang=en"', false)
            ->assertSee('<meta name="description" content="TUM SafeSignal:', false);
    }

    public function test_token_bearing_pages_do_not_announce_alternates(): void
    {
        $report = Report::factory()->create();

        $this->get(route('report.show', ['reporterToken' => $report->reporter_token]))
            ->assertOk()
            ->assertDontSee('hreflang', false);
    }

    public function test_process_page_content_can_be_overridden_via_config(): void
    {
        config(['meldeplattform.process' => '## Eigener Ablauftext']);

        $this->get('/process')
            ->assertOk()
            ->assertSee('Eigener Ablauftext');
    }

    public function test_reporter_sees_acknowledgement_status(): void
    {
        $report = Report::factory()->create();
        $url = route('report.show', ['reporterToken' => $report->reporter_token]);

        $this->withCookie('lang', 'de')->get($url)
            ->assertOk()
            ->assertSee('Eingang noch nicht bestätigt – die zuständige Stelle bestätigt spätestens bis '
                .now()->addDays(7)->format('d.m.Y'));

        $report->acknowledge();

        $this->withCookie('lang', 'de')->get($url)
            ->assertSee('Eingang bestätigt am '.now()->format('d.m.Y'));
    }

    public function test_overdue_acknowledgement_shows_no_past_deadline(): void
    {
        $report = Report::factory()->create(['created_at' => now()->subDays(30)]);

        $this->withCookie('lang', 'de')
            ->get(route('report.show', ['reporterToken' => $report->reporter_token]))
            ->assertSee('die Bestätigung ist überfällig')
            ->assertDontSee('spätestens bis');
    }

    public function test_concluded_unacknowledged_report_shows_no_status_and_no_internal_state(): void
    {
        foreach ([ReportState::Spam, ReportState::Done] as $state) {
            $report = Report::factory()->create(['state' => $state]);

            $this->withCookie('lang', 'de')
                ->get(route('report.show', ['reporterToken' => $report->reporter_token]))
                ->assertOk()
                ->assertDontSee('data-reporter-ack', false)
                ->assertDontSee('Spam');
        }
    }

    public function test_form_page_links_to_process_and_support(): void
    {
        $topic = Topic::factory()->create();

        $this->get('/form/'.$topic->id)
            ->assertOk()
            ->assertSee(route('process'), false)
            ->assertSee(route('support'), false);
    }
}
