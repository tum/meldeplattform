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

    public function test_display_name_attribute_wins_over_everything(): void
    {
        $name = SamlAttributes::displayName([
            'displayName' => ['Max Mustermann'],
            'imanzeigename' => ['Should Not Win'],
            'imtitelanrede' => ['Dr.'],
            'imvorname' => ['Max'],
            'sn' => ['Mustermann'],
        ]);

        $this->assertSame('Max Mustermann', $name);
    }

    public function test_display_name_is_also_read_by_oid(): void
    {
        // An IdP that releases no FriendlyName leaves only the raw OID key.
        $this->assertSame('Erika Musterfrau', SamlAttributes::displayName([
            'urn:oid:2.16.840.1.113730.3.1.241' => ['Erika Musterfrau'],
            'imvorname' => ['Erika'],
        ]));
    }

    public function test_falls_back_to_imanzeigename_before_composing(): void
    {
        $this->assertSame('Ready Made', SamlAttributes::displayName([
            'imanzeigename' => ['Ready Made'],
            'imvorname' => ['Max'],
            'sn' => ['Mustermann'],
        ]));
    }

    public function test_blank_display_name_falls_through(): void
    {
        $this->assertSame('Max Mustermann', SamlAttributes::displayName([
            'displayName' => ['   '],
            'imvorname' => ['Max'],
            'sn' => ['Mustermann'],
        ]));
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
