<?php

namespace Tests\Unit;

use App\Support\SamlAttributes;
use Tests\TestCase;

class SamlAttributesTest extends TestCase
{
    public function test_composes_name_from_title_first_and_surname(): void
    {
        $name = SamlAttributes::displayName([
            'imtitelanrede' => ['Dr.'],
            'imvorname' => ['Max'],
            'sn' => ['Mustermann'],
        ]);

        $this->assertSame('Dr. Max Mustermann', $name);
    }

    public function test_composes_without_title(): void
    {
        $name = SamlAttributes::displayName([
            'imvorname' => ['Erika'],
            'sn' => ['Musterfrau'],
        ]);

        $this->assertSame('Erika Musterfrau', $name);
    }

    public function test_composes_from_surname_alone(): void
    {
        $this->assertSame('Mustermann', SamlAttributes::displayName(['sn' => ['Mustermann']]));
    }

    public function test_falls_back_to_display_name_attribute(): void
    {
        // No imvorname/sn — use the ready-made display name.
        $this->assertSame('Max Mustermann', SamlAttributes::displayName([
            'imanzeigename' => ['Max Mustermann'],
        ]));
    }

    public function test_falls_back_to_legacy_display_name_friendly_name(): void
    {
        $this->assertSame('Legacy Name', SamlAttributes::displayName([
            'displayName' => ['Legacy Name'],
        ]));
    }

    public function test_composed_name_takes_precedence_over_display_name(): void
    {
        $name = SamlAttributes::displayName([
            'imvorname' => ['Max'],
            'sn' => ['Mustermann'],
            'imanzeigename' => ['Should Not Win'],
        ]);

        $this->assertSame('Max Mustermann', $name);
    }

    public function test_returns_null_when_nothing_usable(): void
    {
        $this->assertNull(SamlAttributes::displayName([]));
        // A lone salutation must not become the whole name.
        $this->assertNull(SamlAttributes::displayName(['imtitelanrede' => ['Herr']]));
        // Blank values are treated as absent.
        $this->assertNull(SamlAttributes::displayName(['imvorname' => ['  '], 'sn' => ['']]));
    }

    public function test_first_trims_and_treats_blank_as_absent(): void
    {
        $this->assertSame('ge42tum', SamlAttributes::first(['uid' => ['  ge42tum  ']], 'uid'));
        $this->assertNull(SamlAttributes::first(['uid' => ['   ']], 'uid'));
        $this->assertNull(SamlAttributes::first([], 'uid'));
    }
}
