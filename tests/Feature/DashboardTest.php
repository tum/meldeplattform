<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/dev/login');
    }

    public function test_global_admin_sees_all_reports(): void
    {
        $user = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'GA', 'email' => 'ga@x']);

        $t1 = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);
        $t2 = Topic::create(['name_de' => 'B', 'name_en' => 'B', 'summary_de' => '', 'summary_en' => '']);
        Report::create(['topic_id' => $t1->id]);
        Report::create(['topic_id' => $t2->id]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('A')
            ->assertSee('B');
    }

    public function test_topic_admin_sees_only_their_topics(): void
    {
        $mine = Topic::create(['name_de' => 'Mine', 'name_en' => 'Mine', 'summary_de' => '', 'summary_en' => '']);
        $other = Topic::create(['name_de' => 'Other', 'name_en' => 'Other', 'summary_de' => '', 'summary_en' => '']);
        $admin = Admin::create(['user_id' => 'topicadmin']);
        $mine->admins()->attach($admin);

        $myReport = Report::create(['topic_id' => $mine->id]);
        $otherReport = Report::create(['topic_id' => $other->id]);

        $user = User::updateOrCreate(['uid' => 'topicadmin'], ['name' => 'TA', 'email' => 'ta@x']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('#'.$myReport->id)
            ->assertDontSee('#'.$otherReport->id);
    }
}
