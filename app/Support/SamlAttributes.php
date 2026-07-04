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
     * Resolve a display name from the TUM IdP's LDAP attributes. Prefers a
     * composed "Anrede/Titel Vorname Nachname" built from imtitelanrede +
     * imvorname + sn; falls back to the ready-made display name imanzeigename,
     * then to the legacy `displayName` friendly name. Returns null when nothing
     * usable was released.
     *
     * @param array<string, list<string>> $attrs merged friendly-name + raw attribute map
     */
    public static function displayName(array $attrs): ?string
    {
        $title = self::first($attrs, 'imtitelanrede');
        $first = self::first($attrs, 'imvorname');
        $last = self::first($attrs, 'sn');

        // Compose only when at least a given name or surname is present, so a
        // lone salutation ("Herr") never becomes the whole name.
        if ($first !== null || $last !== null) {
            $composed = trim(implode(' ', array_filter(
                [$title, $first, $last],
                static fn (?string $v): bool => $v !== null,
            )));
            if ($composed !== '') {
                return $composed;
            }
        }

        return self::first($attrs, 'imanzeigename') ?? self::first($attrs, 'displayName');
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
