<?php

namespace App\Support;

/**
 * Pull the human-facing user fields out of the SAML/LDAP attribute set the
 * TUM IdP releases. Kept separate from SamlController so the (branch-heavy)
 * display-name resolution is unit-testable without standing up a full SAML
 * assertion.
 */
class SamlAttributes
{
    /**
     * LDAP `displayName` as the IdP may release it: by FriendlyName, or by
     * its attribute OID when the IdP emits no FriendlyName for it.
     */
    private const DISPLAY_NAME_KEYS = ['displayName', 'urn:oid:2.16.840.1.113730.3.1.241'];

    /**
     * Resolve a display name from the TUM IdP's LDAP attributes. `displayName`
     * is the name the directory itself presents for the person, so it wins.
     * Falls back to TUM's ready-made imanzeigename, then to a composed
     * "Anrede/Titel Vorname Nachname" from imtitelanrede + imvorname + sn.
     * Returns null when nothing usable was released.
     *
     * @param array<string, list<string>> $attrs merged friendly-name + raw attribute map
     */
    public static function displayName(array $attrs): ?string
    {
        foreach (self::DISPLAY_NAME_KEYS as $key) {
            $name = self::first($attrs, $key);
            if ($name !== null) {
                return $name;
            }
        }

        $ready = self::first($attrs, 'imanzeigename');
        if ($ready !== null) {
            return $ready;
        }

        $title = self::first($attrs, 'imtitelanrede');
        $first = self::first($attrs, 'imvorname');
        $last = self::first($attrs, 'sn');

        // Compose only when at least a given name or surname is present, so a
        // lone salutation ("Herr") never becomes the whole name.
        if ($first === null && $last === null) {
            return null;
        }

        $composed = trim(implode(' ', array_filter(
            [$title, $first, $last],
            static fn (?string $v): bool => $v !== null,
        )));

        return $composed !== '' ? $composed : null;
    }

    /**
     * First non-empty value for $key in the attribute map, trimmed; null when
     * the attribute is absent or blank.
     *
     * @param array<string, list<string>> $attrs
     */
    public static function first(array $attrs, string $key): ?string
    {
        if (! isset($attrs[$key]) || count($attrs[$key]) === 0) {
            return null;
        }

        $value = trim($attrs[$key][0]);

        return $value !== '' ? $value : null;
    }
}
