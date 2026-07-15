<?php

/*
|--------------------------------------------------------------------------
| SAML service-provider configuration (onelogin/php-saml).
|--------------------------------------------------------------------------
|
| A single service provider matching the TUM Shibboleth IdP. SamlController
| builds the onelogin settings array from the `sp`, `idp` and `security` keys
| below, and registers its own routes in routes/web.php.
|
| This file previously described 24slides/laravel-saml2 and a `saml2_tenants`
| table. That package is not installed and no such table exists — the header
| and its `useRoutes` / `routesPrefix` / `routesMiddleware` / `attribute_map`
| keys were read by nothing, so editing them to change the SAML route prefix or
| the attribute mapping silently did nothing. Only the keys below are live.
|
*/

return [
    // mirror of the Go service-provider config.
    'sp' => [
        'entityId' => env('SAML2_SP_ENTITYID', env('APP_URL').'/shib'),
        'assertionConsumerService' => [
            'url' => env('APP_URL').'/shib',
        ],
        'singleLogoutService' => [
            'url' => env('APP_URL').'/saml/slo',
        ],
        'x509cert' => env('SAML2_SP_X509CERT', ''),
        'privateKey' => env('SAML2_SP_PRIVATEKEY', ''),
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
    ],

    'idp' => [
        'entityId' => env('SAML2_IDP_ENTITYID'),
        'metadataUrl' => env('SAML2_IDP_METADATA_URL'),
        'singleSignOnService' => [
            'url' => env('SAML2_IDP_SSO_URL'),
        ],
        'singleLogoutService' => [
            'url' => env('SAML2_IDP_SLO_URL'),
        ],
        // PEM-encoded IdP signing certificate (without -----BEGIN/END----- markers
        // also accepted). REQUIRED: without it, the SP cannot verify SAML response
        // signatures and any attacker can forge assertions.
        'x509cert' => env('SAML2_IDP_X509CERT', ''),
    ],

    // Security requirements enforced on inbound SAML responses.
    // Mandatory in production; the SP refuses to boot without an IdP certificate.
    'security' => [
        // The IdP signs the SAML Response (message) envelope, which we require.
        'wantMessagesSigned' => true,
        // The TUM IdP does not separately sign the assertion, so we cannot
        // require it — doing so rejects every login. Trust is established via
        // the signed response envelope above plus the IdP certificate.
        'wantAssertionsSigned' => false,
        'wantAssertionsEncrypted' => false,
        'wantNameIdEncrypted' => false,
        'authnRequestsSigned' => false,
        'signMetadata' => false,
        'rejectUnsolicitedResponsesWithInResponseTo' => true,
        // Do NOT send a <RequestedAuthnContext>. onelogin's default (true) demands
        // an exact PasswordProtectedTransport context, which the TUM IdP rejects
        // with Responder/NoAuthnContext when it authenticates the user any other
        // way (existing SSO session, MFA, etc.). We accept whatever context the
        // IdP used, so we impose no requirement.
        'requestedAuthnContext' => false,
    ],
];
