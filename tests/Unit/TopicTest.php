<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class TopicTest extends TestCase
{
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
}
