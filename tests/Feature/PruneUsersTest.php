<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['meldeplattform.inactive_user_days' => 365]);
    }

    private function seedUser(string $uid, ?Carbon $lastLogin, bool $global = false): User
    {
        return User::create([
            'uid' => $uid,
            'name' => $uid,
            'email' => "{$uid}@example.test",
            'is_global_admin' => $global,
            'last_login_at' => $lastLogin,
        ]);
    }

    public function test_prunes_role_less_inactive_user(): void
    {
        $this->seedUser('stale', Carbon::now()->subDays(400));

        $this->assertSame(0, Artisan::call('users:prune'));

        $this->assertDatabaseMissing('users', ['uid' => 'stale']);
    }

    public function test_keeps_recently_active_user(): void
    {
        $this->seedUser('fresh', Carbon::now()->subDays(10));

        Artisan::call('users:prune');

        $this->assertDatabaseHas('users', ['uid' => 'fresh']);
    }

    public function test_keeps_global_admins_even_when_dormant(): void
    {
        config(['meldeplattform.admin_users' => ['envadmin']]);
        $this->seedUser('dbadmin', Carbon::now()->subDays(400), global: true);
        $this->seedUser('envadmin', Carbon::now()->subDays(400));

        Artisan::call('users:prune');

        $this->assertDatabaseHas('users', ['uid' => 'dbadmin']);
        $this->assertDatabaseHas('users', ['uid' => 'envadmin']);
    }

    public function test_keeps_topic_admins_even_when_dormant(): void
    {
        $this->seedUser('topicadmin', Carbon::now()->subDays(400));
        Admin::create(['user_id' => 'topicadmin']);

        Artisan::call('users:prune');

        $this->assertDatabaseHas('users', ['uid' => 'topicadmin']);
    }

    public function test_falls_back_to_created_at_when_last_login_null(): void
    {
        $user = $this->seedUser('legacy', null);
        // Eloquent stamps created_at on insert, so age the row directly.
        DB::table('users')->where('id', $user->id)->update(['created_at' => Carbon::now()->subDays(400)]);

        Artisan::call('users:prune');

        $this->assertDatabaseMissing('users', ['uid' => 'legacy']);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->seedUser('stale', Carbon::now()->subDays(400));

        $this->assertSame(0, Artisan::call('users:prune', ['--dry-run' => true]));

        $this->assertDatabaseHas('users', ['uid' => 'stale']);
    }

    public function test_no_op_when_window_disabled(): void
    {
        config(['meldeplattform.inactive_user_days' => null]);
        $this->seedUser('stale', Carbon::now()->subDays(400));

        $this->assertSame(0, Artisan::call('users:prune'));

        $this->assertDatabaseHas('users', ['uid' => 'stale']);
    }
}
