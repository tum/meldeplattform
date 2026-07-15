<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /users/{uid}/edit form disables the `is_global_admin` checkbox in two
 * cases (an env-listed global admin, and a global admin editing themselves).
 * Browsers do not submit disabled controls, and this form carries the user's
 * complete desired state — so an absent field reads as "not a global admin".
 *
 * These tests build the payload from the rendered markup instead of hand-writing
 * it, because a hand-written payload always includes the field and therefore
 * cannot reproduce the bug.
 */
class UserGlobalAdminFormTest extends TestCase
{
    use RefreshDatabase;

    private function dbGlobalAdmin(string $uid): User
    {
        // Global via the DB flag rather than the env allowlist, so the
        // is_global_admin column is genuinely load-bearing for this user.
        return User::create([
            'uid' => $uid,
            'name' => $uid,
            'email' => "{$uid}@example.com",
            'is_global_admin' => true,
        ]);
    }

    /**
     * The payload a browser would submit from the edit form: every non-disabled
     * `is_global_admin` input that would actually be sent. Unticked checkboxes
     * and disabled controls are omitted, exactly as a browser omits them.
     *
     * @return array<string, string>
     */
    private function browserGlobalAdminPayload(string $uid): array
    {
        $html = (string) $this->get(route('users.edit', ['uid' => $uid]))
            ->assertOk()
            ->getContent();

        preg_match_all('/<input[^>]*name="is_global_admin"[^>]*>/', $html, $tags);

        foreach ($tags[0] as $tag) {
            if (str_contains($tag, 'disabled')) {
                continue;
            }
            if (str_contains($tag, 'type="checkbox"') && ! str_contains($tag, 'checked')) {
                continue;
            }

            return ['is_global_admin' => preg_match('/value="([^"]*)"/', $tag, $v) === 1 ? $v[1] : 'on'];
        }

        return [];
    }

    public function test_global_admin_can_save_their_own_topic_access(): void
    {
        $self = $this->dbGlobalAdmin('dbglobal');
        $topic = Topic::factory()->create();
        $this->actingAs($self);

        $this->post(
            route('users.update', ['uid' => 'dbglobal']),
            $this->browserGlobalAdminPayload('dbglobal') + ['topic_ids' => [$topic->id]],
        )->assertSessionHasNoErrors();

        $this->assertTrue($self->fresh()?->is_global_admin, 'self-edit demoted the actor');
        $this->assertTrue(
            Admin::where('user_id', 'dbglobal')->first()?->topics->contains($topic->id) ?? false,
            'the topic assignment was discarded',
        );
    }

    public function test_editing_an_env_global_admin_does_not_clear_their_db_flag(): void
    {
        // 'globaladmin' is the env-allowlisted UID seeded by TestCase.
        $envAdmin = User::create([
            'uid' => 'globaladmin',
            'name' => 'Env Admin',
            'email' => 'env@example.com',
            'is_global_admin' => true,
        ]);
        $topic = Topic::factory()->create();
        $this->actingAs($this->dbGlobalAdmin('otherglobal'));

        $this->post(
            route('users.update', ['uid' => 'globaladmin']),
            $this->browserGlobalAdminPayload('globaladmin') + ['topic_ids' => [$topic->id]],
        )->assertSessionHasNoErrors();

        // The flag is masked by the env allowlist today; silently clearing it
        // would strip global admin the moment the UID leaves MELDE_ADMIN_USERS.
        $this->assertTrue($envAdmin->fresh()?->is_global_admin, 'env-listed admin was silently demoted');
    }

    public function test_explicit_self_demotion_is_still_rejected(): void
    {
        // The lockout guard must survive the fix above: an actor who really
        // does post is_global_admin=0 for themselves is still refused.
        $self = $this->dbGlobalAdmin('dbglobal');

        $this->actingAs($self)->post(route('users.update', ['uid' => 'dbglobal']), [
            'is_global_admin' => '0',
            'topic_ids' => [],
        ])->assertSessionHasErrors('is_global_admin');

        $this->assertTrue($self->fresh()?->is_global_admin);
    }

    public function test_a_global_admin_can_still_demote_someone_else(): void
    {
        $target = $this->dbGlobalAdmin('target');
        $this->actingAs($this->dbGlobalAdmin('actor'));

        $this->post(route('users.update', ['uid' => 'target']), [
            'is_global_admin' => '0',
            'topic_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertFalse($target->fresh()?->is_global_admin);
    }
}
