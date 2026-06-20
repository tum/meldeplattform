<?php

/*
|--------------------------------------------------------------------------
| 24slides/laravel-saml2 configuration (multi-tenant package).
|--------------------------------------------------------------------------
|
| We operate a single tenant matching the TUM Shibboleth IdP. Tenants are
| stored in the `saml2_tenants` DB table. For convenience we also expose
| global defaults below that can be consumed from seeders and the
| Saml2Controller.
|
*/

return [
    'useRoutes' => true,
    'routesPrefix' => 'saml',
    'routesMiddleware' => ['web'],

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
    ],

    // Friendly names of SAML attributes we pull into the session.
    'attribute_map' => [
        'uid' => 'uid',
        'displayName' => 'displayName',
        'mail' => 'mail',
    ],
];
