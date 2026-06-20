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
}
