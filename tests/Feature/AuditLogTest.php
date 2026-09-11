<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_change_records_audit_entry_with_from_and_to(): void
    {
        $topic = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $report = Report::create(['topic_id' => $topic->id]);

        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$topic->id}/report/{$report->id}/status", ['s' => 'close'])
            ->assertOk();

        $entry = AuditLog::where('action', 'report.status_changed')->firstOrFail();
        $this->assertSame('globaladmin', $entry->actor);
        $this->assertSame('report', $entry->subject_type);
        $this->assertSame($report->id, $entry->subject_id);
        $this->assertSame('open', $entry->metadata['from'] ?? null);
        $this->assertSame('done', $entry->metadata['to'] ?? null);
        // globaladmin is env-only in tests, so the flag should be present.
        $this->assertTrue($entry->metadata['admin_via_env'] ?? false);
    }

    public function test_bulk_status_change_records_summary_entry(): void
    {
        $topic = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $r1 = Report::create(['topic_id' => $topic->id]);
        $r2 = Report::create(['topic_id' => $topic->id]);

        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$topic->id}/reports/status", ['ids' => [$r1->id, $r2->id], 's' => 'spam'])
            ->assertOk();

        $entry = AuditLog::where('action', 'report.bulk_status_changed')->firstOrFail();
        $this->assertSame('topic', $entry->subject_type);
        $this->assertSame($topic->id, $entry->subject_id);
        $this->assertSame('spam', $entry->metadata['to'] ?? null);
        $this->assertSame(2, $entry->metadata['count'] ?? null);
        $this->assertEqualsCanonicalizing([$r1->id, $r2->id], $entry->metadata['report_ids'] ?? []);
    }

    public function test_admin_report_access_records_entry(): void
    {
        $topic = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $report = Report::create(['topic_id' => $topic->id]);

        $this->actingAsGlobalAdmin()
            ->get("/reports/{$topic->id}/{$report->id}")
            ->assertOk();

        $entry = AuditLog::where('action', 'report.accessed')->firstOrFail();
        $this->assertSame('report', $entry->subject_type);
        $this->assertSame($report->id, $entry->subject_id);
    }

    public function test_reporter_report_access_is_not_logged(): void
    {
        $topic = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $report = Report::create(['topic_id' => $topic->id]);

        $this->get('/report?reporterToken='.$report->reporter_token)->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'report.accessed')->count());
    }

    public function test_granting_admin_records_entry(): void
    {
        $this->actingAsGlobalAdmin();
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);

        $this->post('/users', [
            'uid' => 'newadmin',
            'is_global_admin' => '0',
            'topic_ids' => [$topic->id],
        ])->assertRedirect('/users');

        $entry = AuditLog::where('action', 'admin.granted')->firstOrFail();
        $this->assertSame('newadmin', $entry->metadata['target_uid'] ?? null);
        $this->assertFalse($entry->metadata['is_global_admin'] ?? null);
        $this->assertSame([$topic->id], $entry->metadata['topic_ids'] ?? null);
    }

    public function test_revoking_admin_records_entry(): void
    {
        $this->actingAsGlobalAdmin();
        User::create(['uid' => 'target', 'name' => 'T', 'email' => 't@x', 'is_global_admin' => true]);

        $this->delete('/users/target')->assertRedirect('/users');

        $entry = AuditLog::where('action', 'admin.revoked')->firstOrFail();
        $this->assertSame('target', $entry->metadata['target_uid'] ?? null);
    }

    public function test_creating_topic_records_topic_created(): void
    {
        $this->actingAsGlobalAdmin()->postJson('/api/topic', [
            'ID' => 0,
            'Name' => ['de' => 'Neu', 'en' => 'New'],
            'Fields' => [['Name' => ['de' => 'F', 'en' => 'F'], 'Type' => 'text']],
        ])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'topic.created')->count());
        $this->assertSame(0, AuditLog::where('action', 'topic.updated')->count());
    }

    public function test_updating_topic_records_topic_updated(): void
    {
        $topic = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);

        $this->actingAsGlobalAdmin()->postJson("/api/topic/{$topic->id}", [
            'ID' => $topic->id,
            'Name' => ['de' => 'B', 'en' => 'B'],
            'Fields' => [['Name' => ['de' => 'F', 'en' => 'F'], 'Type' => 'text']],
        ])->assertOk();

        $entry = AuditLog::where('action', 'topic.updated')->firstOrFail();
        $this->assertSame('topic', $entry->subject_type);
        $this->assertSame($topic->id, $entry->subject_id);
        $this->assertSame(0, AuditLog::where('action', 'topic.created')->count());
    }

    public function test_actor_is_system_without_authenticated_user(): void
    {
        $entry = AuditLog::record('topic.created', null, ['topic_id' => 1]);
        $this->assertSame('system', $entry->actor);
    }

    public function test_audit_entries_cannot_be_updated(): void
    {
        $entry = AuditLog::record('admin.granted', null, ['target_uid' => 'x']);

        $this->expectException(RuntimeException::class);
        $entry->action = 'tampered';
        $entry->save();
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        $entry = AuditLog::record('admin.granted', null, ['target_uid' => 'x']);

        $this->expectException(RuntimeException::class);
        $entry->delete();
    }

    public function test_audit_page_forbidden_for_non_global_admin(): void
    {
        $this->actingAsUser('regular')->get('/audit')->assertStatus(403);
    }

    public function test_audit_page_visible_to_global_admin(): void
    {
        $this->actingAsGlobalAdmin()->get('/audit')->assertOk();
    }

    public function test_topbar_marks_current_section(): void
    {
        $response = $this->actingAsGlobalAdmin()->get('/audit')->assertOk();

        // Only the audit link carries the active state; the others don't.
        $response->assertSee('class="is-active"', false)
            ->assertSee('aria-current="page">Audit log</a>', false)
            ->assertDontSee('aria-current="page">Dashboard</a>', false)
            ->assertSee('aria-pressed="true"><abbr lang="en"', false)
            // Small-screen toggle ships closed and points at the menu panel.
            ->assertSee('data-menu="closed"', false)
            ->assertSee('aria-expanded="false" aria-controls="topbar-menu"', false)
            ->assertSee('id="topbar-menu"', false);
    }

    public function test_assets_are_versioned_so_browsers_refetch_after_deploy(): void
    {
        // Without a version the CSS and JS URLs never change, so a browser
        // that cached them under heuristic freshness keeps serving the old
        // script long after a deploy — new markup, dead toggle.
        $this->actingAsGlobalAdmin()->get('/audit')
            ->assertOk()
            ->assertSee('/css/app.css?v='.filemtime(public_path('css/app.css')), false)
            ->assertSee('/js/app.js?v='.filemtime(public_path('js/app.js')), false);
    }

    public function test_audit_table_carries_card_labels_for_small_screens(): void
    {
        AuditLog::record('report.accessed', null, ['source' => 'otrs']);

        // Below 720px the stylesheet renders each row as a card and takes
        // the per-cell label from data-label; the markup must provide it.
        $this->actingAsGlobalAdmin()->get('/audit')
            ->assertOk()
            ->assertSee('<table class="table-cards">', false)
            ->assertSee('<td data-label="Actor">', false)
            ->assertSee('<td data-label="Details">', false)
            ->assertSee('class="cell-title"', false);
    }

    public function test_audit_page_renders_metadata_as_key_value_chips(): void
    {
        AuditLog::record('report.bulk_status_changed', null, ['to' => 'done', 'report_ids' => [4, 7]]);

        $this->actingAsGlobalAdmin()->get('/audit')
            ->assertOk()
            ->assertSee('<span class="kv-key">to</span>', false)
            ->assertSee('<span class="kv-value">done</span>', false)
            ->assertSee('<span class="kv-value">[4,7]</span>', false)
            // Actions carry their domain so the stylesheet can colour-code them.
            ->assertSee('data-domain="report"', false);
    }

    public function test_audit_page_uses_themed_paginator(): void
    {
        // 51 entries → page size 50 → a second page exists.
        for ($i = 0; $i < 51; $i++) {
            AuditLog::record('report.accessed');
        }

        $response = $this->actingAsGlobalAdmin()->get('/audit')->assertOk();

        // The stock Laravel template is written against Tailwind, which this
        // app does not ship, so its arrows render unsized. Guard against a
        // regression to it.
        $response->assertSee('class="pagination"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('rel="next"', false)
            ->assertDontSee('class="w-5 h-5"', false);
    }
}
