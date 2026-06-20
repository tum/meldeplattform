<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_and_summary_respect_language(): void
    {
        $t = new Topic;
        $t->name_de = 'Name DE';
        $t->name_en = 'Name EN';
        $t->summary_de = 'Zusammenfassung';
        $t->summary_en = 'Summary';

        $this->assertSame('Name DE', $t->name('de'));
        $this->assertSame('Name EN', $t->name('en'));
        $this->assertSame('Zusammenfassung', $t->summary('de'));
        $this->assertSame('Summary', $t->summary('en'));
    }

    public function test_name_falls_back_if_localization_missing(): void
    {
        $t = new Topic;
        $t->name_de = '';
        $t->name_en = 'English only';
        $this->assertSame('English only', $t->name('de'));
    }

    public function test_is_admin_checks_related_admins(): void
    {
        $t = new Topic;
        $a = new Admin;
        $a->user_id = 'ge42tum';
        $t->setRelation('admins', Collection::make([$a]));

        $this->assertTrue($t->isAdmin('ge42tum'));
        $this->assertFalse($t->isAdmin('someoneelse'));
        $this->assertFalse($t->isAdmin(''));
        $this->assertFalse($t->isAdmin(null));
    }

    public function test_manageable_by_returns_all_topics_for_global_admin(): void
    {
        $a = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);
        $b = Topic::create(['name_de' => 'B', 'name_en' => 'B', 'summary_de' => '', 'summary_en' => '']);

        // 'globaladmin' is allowlisted via TestCase::setUp.
        $user = User::create(['uid' => 'globaladmin', 'name' => 'GA', 'email' => 'ga@x']);

        $ids = Topic::query()->manageableBy($user)->pluck('id')->all();

        // A global admin sees every topic in the database (the count guards
        // against the scope silently narrowing the set).
        $this->assertEqualsCanonicalizing(Topic::pluck('id')->all(), $ids);
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    public function test_manageable_by_limits_topic_admin_to_assigned_topics(): void
    {
        $mine = Topic::create(['name_de' => 'Mine', 'name_en' => 'Mine', 'summary_de' => '', 'summary_en' => '']);
        $other = Topic::create(['name_de' => 'Other', 'name_en' => 'Other', 'summary_de' => '', 'summary_en' => '']);

        $admin = Admin::create(['user_id' => 'topicadmin']);
        $mine->admins()->attach($admin);

        $user = User::create(['uid' => 'topicadmin', 'name' => 'TA', 'email' => 'ta@x']);

        $ids = Topic::query()->manageableBy($user)->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_manageable_by_returns_nothing_for_user_without_assignments(): void
    {
        Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);

        $user = User::create(['uid' => 'nobody', 'name' => 'N', 'email' => 'n@x']);

        $this->assertSame([], Topic::query()->manageableBy($user)->pluck('id')->all());
    }
}
