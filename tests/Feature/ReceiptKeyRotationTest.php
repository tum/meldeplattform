<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Receipt codes are HMAC'd with APP_KEY, and Laravel already honours
 * app.previous_keys for sessions and cookies — so rotating APP_KEY is a
 * supported operation an operator will reach for after a suspected key leak.
 *
 * The lookup ignored previous keys, so that rotation quietly destroyed the
 * anonymous access channel: every outstanding code stopped resolving and
 * reporters with open cases could never return, reply, or receive their
 * statutory feedback. The security response destroyed reporter access.
 */
class ReceiptKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private const NEW_KEY = 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';

    /** @return array{0: Report, 1: string} */
    private function reportWithCode(): array
    {
        config(['app.key' => self::OLD_KEY, 'app.previous_keys' => []]);

        $topic = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $report = Report::create(['topic_id' => $topic->id]);

        return [$report, $report->issueReceiptCode()];
    }

    private function rotate(): void
    {
        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => [self::OLD_KEY]]);
    }

    public function test_a_code_issued_before_a_key_rotation_still_resolves(): void
    {
        [$report, $code] = $this->reportWithCode();

        $this->rotate();

        $this->assertSame($report->id, Report::findByReceiptCode($code)?->id);
    }

    public function test_a_reporter_can_still_track_their_report_after_a_rotation(): void
    {
        [$report, $code] = $this->reportWithCode();

        $this->rotate();

        $this->post(route('report.track.submit'), ['code' => $code])
            ->assertRedirect(route('report.show', ['reporterToken' => $report->reporter_token]));
    }

    public function test_codes_issued_after_a_rotation_use_the_current_key(): void
    {
        [$existing] = $this->reportWithCode();
        $this->rotate();

        $fresh = Report::create(['topic_id' => $existing->topic_id]);
        $code = $fresh->issueReceiptCode();

        // Resolvable now...
        $found = Report::findByReceiptCode($code);
        $this->assertSame($fresh->id, $found?->id);

        // ...and still resolvable once the old key is dropped, proving it was
        // hashed with the current key rather than a previous one.
        config(['app.previous_keys' => []]);
        $stillFound = Report::findByReceiptCode($code);
        $this->assertSame($fresh->id, $stillFound?->id);
    }

    public function test_a_code_stops_resolving_once_its_key_is_dropped_entirely(): void
    {
        // Previous keys are a migration window, not a permanent skeleton key.
        [, $code] = $this->reportWithCode();

        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => []]);

        $this->assertNull(Report::findByReceiptCode($code));
    }

    public function test_an_unknown_code_still_resolves_to_nothing(): void
    {
        $this->reportWithCode();
        $this->rotate();

        $this->assertNull(Report::findByReceiptCode('DEADBEEFDEADBEEFDEADBEEF'));
    }
}
