<?php

/*
|--------------------------------------------------------------------------
| OpenID Connect (OIDC) configuration
|--------------------------------------------------------------------------
|
| The application authenticates users against TUM's OIDC Identity Provider.
| In the happy path only OIDC_DISCOVERY_URL, OIDC_CLIENT_ID, and
| OIDC_CLIENT_SECRET need to be set per environment — the runtime fetches
| authorization/token/userinfo/jwks endpoints from the discovery document
| and caches them. If the IdP does not publish a discovery document, the
| `manual_endpoints` block below acts as a fallback.
|
| The IdP-trust contract: OidcDiscovery refuses to serve when neither a
| discovery URL nor a complete `manual_endpoints` block is configured, and
| OidcJwks refuses to serve if the JWKS contains no usable signing keys.
| This mirrors the SAML SP's prior posture of refusing to boot without an
| IdP signing certificate.
|
*/

return [
    'discovery_url' => env('OIDC_DISCOVERY_URL', ''),

    // Optional: explicit issuer override. If empty, the issuer claim from
    // the discovery document is trusted as-is.
    'issuer' => env('OIDC_ISSUER', ''),

    'client_id' => env('OIDC_CLIENT_ID', ''),
    'client_secret' => env('OIDC_CLIENT_SECRET', ''),

    'redirect_uri' => env('OIDC_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/').'/auth/callback'),
    'post_logout_redirect_uri' => env('OIDC_POST_LOGOUT_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/').'/'),

    'scopes' => array_values(array_filter(array_map(
        'trim',
        explode(' ', (string) env('OIDC_SCOPES', 'openid profile email')),
    ))),

    // Claim mapped to the local `uid`. TUM populates `preferred_username`
    // with the kennung (e.g. ge25bof), keeping MELDE_ADMIN_USERS compatible.
    // Set to `sub` for opaque identifiers (then migrate the admin list).
    'user_id_claim' => env('OIDC_USER_ID_CLAIM', 'preferred_username'),

    'name_claim' => env('OIDC_NAME_CLAIM', 'name'),
    'email_claim' => env('OIDC_EMAIL_CLAIM', 'email'),

    'discovery_cache_ttl' => (int) env('OIDC_DISCOVERY_CACHE_TTL', 3600),
    'jwks_cache_ttl' => (int) env('OIDC_JWKS_CACHE_TTL', 3600),

    // Clock-skew tolerance for `exp`/`iat`/`nbf` validation.
    'leeway_seconds' => (int) env('OIDC_LEEWAY_SECONDS', 60),

    // Fallback used when `discovery_url` is empty. Populate every key
    // (except `end_session_endpoint`, which is optional).
    'manual_endpoints' => [
        'issuer' => env('OIDC_ISSUER', ''),
        'authorization_endpoint' => env('OIDC_AUTH_ENDPOINT', ''),
        'token_endpoint' => env('OIDC_TOKEN_ENDPOINT', ''),
        'userinfo_endpoint' => env('OIDC_USERINFO_ENDPOINT', ''),
        'jwks_uri' => env('OIDC_JWKS_URI', ''),
        'end_session_endpoint' => env('OIDC_END_SESSION_ENDPOINT', ''),
    ],
];
