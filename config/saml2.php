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
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:persistent',
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
    ],

    // Friendly names of SAML attributes we pull into the session.
    'attribute_map' => [
        'uid' => 'uid',
        'displayName' => 'displayName',
        'mail' => 'mail',
    ],
];
