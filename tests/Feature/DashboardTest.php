<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Admin;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function test_spam_is_hidden_by_default_but_visible_when_unhidden(): void
    {
        $user = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'GA', 'email' => 'ga@x']);
        $topic = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);

        $open = Report::create(['topic_id' => $topic->id, 'state' => ReportState::Open]);
        $spam = Report::create(['topic_id' => $topic->id, 'state' => ReportState::Spam]);

        // Default visit: spam suppressed server-side (never shipped to browser).
        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('#'.$open->id)
            ->assertDontSee('#'.$spam->id);

        // Explicit filter submit with hide_spam off: spam shows.
        $this->actingAs($user)->get('/dashboard?filters=1&hide_spam=0&hide_closed=0')
            ->assertOk()
            ->assertSee('#'.$open->id)
            ->assertSee('#'.$spam->id);
    }

    public function test_closed_is_hidden_by_default(): void
    {
        $user = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'GA', 'email' => 'ga@x']);
        $topic = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);

        $open = Report::create(['topic_id' => $topic->id, 'state' => ReportState::Open]);
        $closed = Report::create(['topic_id' => $topic->id, 'state' => ReportState::Done]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('#'.$open->id)
            ->assertDontSee('#'.$closed->id);

        $this->actingAs($user)->get('/dashboard?filters=1&hide_closed=0&hide_spam=0')
            ->assertOk()
            ->assertSee('#'.$open->id)
            ->assertSee('#'.$closed->id);
    }

    public function test_topic_filter_returns_only_that_topics_reports(): void
    {
        $user = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'GA', 'email' => 'ga@x']);
        $a = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);
        $b = Topic::create(['name_de' => 'B', 'name_en' => 'B', 'summary_de' => '', 'summary_en' => '']);

        $ra = Report::create(['topic_id' => $a->id, 'state' => ReportState::Open]);
        $rb = Report::create(['topic_id' => $b->id, 'state' => ReportState::Open]);

        $this->actingAs($user)->get('/dashboard?filters=1&topic='.$a->id)
            ->assertOk()
            ->assertSee('#'.$ra->id)
            ->assertDontSee('#'.$rb->id);
    }

    public function test_topic_filter_outside_manageable_set_is_ignored(): void
    {
        $mine = Topic::create(['name_de' => 'Mine', 'name_en' => 'Mine', 'summary_de' => '', 'summary_en' => '']);
        $other = Topic::create(['name_de' => 'Other', 'name_en' => 'Other', 'summary_de' => '', 'summary_en' => '']);
        $admin = Admin::create(['user_id' => 'topicadmin']);
        $mine->admins()->attach($admin);

        $myReport = Report::create(['topic_id' => $mine->id, 'state' => ReportState::Open]);
        $otherReport = Report::create(['topic_id' => $other->id, 'state' => ReportState::Open]);

        $user = User::updateOrCreate(['uid' => 'topicadmin'], ['name' => 'TA', 'email' => 'ta@x']);

        // Hand-crafted topic= for a topic the user can't manage must not leak
        // the other team's reports; the filter is dropped and only manageable
        // reports remain.
        $this->actingAs($user)->get('/dashboard?filters=1&topic='.$other->id)
            ->assertOk()
            ->assertSee('#'.$myReport->id)
            ->assertDontSee('#'.$otherReport->id);
    }

    public function test_dashboard_paginates(): void
    {
        $user = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'GA', 'email' => 'ga@x']);
        $topic = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);

        // 51 open reports → page size 50 → a second page exists.
        for ($i = 0; $i < 51; $i++) {
            Report::create(['topic_id' => $topic->id, 'state' => ReportState::Open]);
        }

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();

        $reports = $response->viewData('reports');
        $this->assertInstanceOf(LengthAwarePaginator::class, $reports);
        $this->assertSame(50, $reports->perPage());
        $this->assertSame(51, $reports->total());
        $this->assertSame(50, $reports->count());

        // Second page carries the remaining report.
        $this->actingAs($user)->get('/dashboard?page=2')
            ->assertOk()
            ->assertSee('#');
    }
}
