<?php

namespace Tests\Feature;

use App\Mail\DormantAdminsRevoked;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PruneDormantAdminsTest extends TestCase
{
    use RefreshDatabase;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['meldeplattform.dormant_admin_days' => 365]);
        Mail::fake();
        $this->topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);
        // A global admin with an address, so the summary mail has a recipient.
        User::create(['uid' => 'globaladmin', 'name' => 'G', 'email' => 'ga@example.test', 'last_login_at' => now()]);
    }

    private function topicAdmin(string $uid, ?Carbon $lastLogin): User
    {
        $user = User::create(['uid' => $uid, 'name' => $uid, 'email' => "{$uid}@example.test", 'last_login_at' => $lastLogin]);
        Admin::create(['user_id' => $uid])->topics()->attach($this->topic);

        return $user;
    }

    public function test_revokes_topic_admin_dormant_past_window(): void
    {
        $this->topicAdmin('sleepy', now()->subDays(400));

        $this->assertSame(0, Artisan::call('admins:prune'));

        // Assignments gone, login record kept (users:prune owns that).
        $this->assertDatabaseMissing('admins', ['user_id' => 'sleepy']);
        $this->assertDatabaseHas('users', ['uid' => 'sleepy']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.revoked', 'actor' => 'system']);
        $entry = AuditLog::where('action', 'admin.revoked')->firstOrFail();
        $this->assertSame('sleepy', $entry->metadata['target_uid'] ?? null);
        $this->assertSame('dormant', $entry->metadata['reason'] ?? null);

        Mail::assertSent(DormantAdminsRevoked::class, function (DormantAdminsRevoked $mail): bool {
            return $mail->hasTo('ga@example.test') && $mail->revoked[0]['uid'] === 'sleepy';
        });
    }

    public function test_clears_db_global_admin_flag_when_dormant(): void
    {
        User::create(['uid' => 'oldglobal', 'name' => 'O', 'email' => 'o@example.test', 'is_global_admin' => true, 'last_login_at' => now()->subDays(500)]);

        Artisan::call('admins:prune');

        $this->assertFalse(User::where('uid', 'oldglobal')->firstOrFail()->is_global_admin);
    }

    public function test_keeps_recently_active_admin(): void
    {
        $this->topicAdmin('active', now()->subDays(30));

        Artisan::call('admins:prune');

        $this->assertDatabaseHas('admins', ['user_id' => 'active']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.revoked']);
        Mail::assertNothingSent();
    }

    public function test_never_touches_env_admins(): void
    {
        // `globaladmin` is the env-allowlisted UID (see TestCase::setUp).
        User::where('uid', 'globaladmin')->update(['last_login_at' => now()->subDays(1000)]);
        Admin::create(['user_id' => 'globaladmin'])->topics()->attach($this->topic);

        Artisan::call('admins:prune');

        $this->assertDatabaseHas('admins', ['user_id' => 'globaladmin']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.revoked']);
    }

    public function test_drops_pre_assigned_admin_who_never_logged_in(): void
    {
        $this->travelTo(now()->subDays(400));
        Admin::create(['user_id' => 'ghost'])->topics()->attach($this->topic);
        $this->travelBack();
        // A fresh pre-assignment must survive.
        Admin::create(['user_id' => 'newcomer'])->topics()->attach($this->topic);

        Artisan::call('admins:prune');

        $this->assertDatabaseMissing('admins', ['user_id' => 'ghost']);
        $this->assertDatabaseHas('admins', ['user_id' => 'newcomer']);
        $entry = AuditLog::where('action', 'admin.revoked')->firstOrFail();
        $this->assertSame('never_logged_in', $entry->metadata['reason'] ?? null);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->topicAdmin('sleepy', now()->subDays(400));

        Artisan::call('admins:prune', ['--dry-run' => true]);

        $this->assertDatabaseHas('admins', ['user_id' => 'sleepy']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.revoked']);
        Mail::assertNothingSent();
        $this->assertStringContainsString('would revoke admin access of sleepy', Artisan::output());
    }

    public function test_no_op_when_window_disabled(): void
    {
        config(['meldeplattform.dormant_admin_days' => null]);
        $this->topicAdmin('sleepy', now()->subDays(400));

        Artisan::call('admins:prune');

        $this->assertDatabaseHas('admins', ['user_id' => 'sleepy']);
        $this->assertStringContainsString('disabled', Artisan::output());
    }
}
