<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validation + business-rule coverage for the /users create/update form,
 * exercised through the HTTP layer so the UpsertUserRequest rules and the
 * UpsertUserAccess action are tested together.
 */
class UpsertUserRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_uid_is_rejected_as_required(): void
    {
        $this->actingAsGlobalAdmin();

        $this->from('/users')
            ->post('/users', ['uid' => '', 'topic_ids' => []])
            ->assertRedirect('/users')
            ->assertSessionHasErrors(['uid' => __('users_uid_required')]);
    }

    public function test_non_alphanumeric_uid_is_rejected_as_invalid(): void
    {
        $this->actingAsGlobalAdmin();

        $this->from('/users')
            ->post('/users', ['uid' => 'evil/uid', 'topic_ids' => []])
            ->assertRedirect('/users')
            ->assertSessionHasErrors(['uid' => __('users_uid_invalid')]);
    }

    public function test_uid_is_trimmed_before_validation_and_persistence(): void
    {
        $this->actingAsGlobalAdmin();
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);

        $this->post('/users', ['uid' => '  spaced  ', 'topic_ids' => [$topic->id]])
            ->assertRedirect('/users');

        $this->assertDatabaseHas('admins', ['user_id' => 'spaced']);
    }

    public function test_topic_ids_must_reference_existing_topics(): void
    {
        $this->actingAsGlobalAdmin();

        $this->from('/users')
            ->post('/users', ['uid' => 'someadmin', 'topic_ids' => [999999]])
            ->assertRedirect('/users')
            ->assertSessionHasErrors(['topic_ids.0']);

        $this->assertDatabaseMissing('admins', ['user_id' => 'someadmin']);
    }

    public function test_cannot_grant_global_admin_to_pending_user(): void
    {
        $this->actingAsGlobalAdmin();
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);

        // 'pending' has no users row yet, so global admin cannot be assigned.
        $this->from('/users')
            ->post('/users', ['uid' => 'pending', 'is_global_admin' => '1', 'topic_ids' => [$topic->id]])
            ->assertRedirect('/users')
            ->assertSessionHasErrors(['is_global_admin' => __('users_cannot_set_global_admin_pending')]);

        $this->assertDatabaseMissing('admins', ['user_id' => 'pending']);
    }

    public function test_cannot_self_demote_via_request(): void
    {
        $u = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'GA', 'email' => 'ga@x']);
        $u->is_global_admin = true;
        $u->save();
        $this->actingAs($u);

        $this->from('/users/globaladmin/edit')
            ->post('/users/globaladmin', ['is_global_admin' => '0', 'topic_ids' => []])
            ->assertRedirect('/users/globaladmin/edit')
            ->assertSessionHasErrors(['is_global_admin' => __('users_cannot_self_demote')]);

        $this->assertTrue($u->fresh()?->is_global_admin);
    }

    public function test_valid_payload_syncs_topics_and_global_flag(): void
    {
        $this->actingAsGlobalAdmin();
        $user = User::create(['uid' => 'target', 'name' => 'T', 'email' => 't@x']);
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);

        $this->post('/users/target', ['is_global_admin' => '1', 'topic_ids' => [$topic->id]])
            ->assertRedirect('/users');

        $this->assertTrue($user->fresh()?->is_global_admin);
        $admin = Admin::where('user_id', 'target')->firstOrFail();
        $this->assertEqualsCanonicalizing([$topic->id], $admin->topics->pluck('id')->all());
    }
}
