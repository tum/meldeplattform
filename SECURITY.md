# Security Audit – TUM Meldeplattform (Laravel Port)

**Scope:** full application review after the Laravel 13 port – Controllers,
Middleware, FormRequests, Models, Services, Blade templates, routes, config,
Docker setup, CI.
**Audit date:** 2026-04-18
**Laravel version:** 13.5.0 · **PHP:** 8.3

This document captures the threat model, the findings, the fixes that were
applied inline during the audit, and the open recommendations for whoever
deploys the platform.

---

## Threat model

| Actor | Trust | Capabilities |
|---|---|---|
| **Anonymous reporter** | Untrusted | Open a form for any topic, submit text/files, get back a reporter token, reply via the token. |
| **Tokenised admin** | Semi-trusted | Holds an administrator token (shared by e-mail on report creation). Can read/reply to a single report, change its status. |
| **Topic admin** | Trusted (SAML UID) | Can edit the topic, see/reply to every report on that topic. |
| **Global admin** | Trusted (SAML UID + env allowlist) | Can create topics, has topic-admin powers on every topic. |
| **IdP (TUM Shibboleth)** | Trusted | Issues signed SAML assertions. |
| **SMTP / Matrix / Webhook targets** | Operator-controlled | External sinks configured per topic by trusted admins. |

Assets we protect:

1. Contents of anonymous reports (text + uploaded files).
2. The pseudonymity of reporters (no IP logging, no persistent tracking).
3. Report-routing integrity (a report must reach the topic admins it was
   submitted to, and nobody else).
4. Admin accounts (SAML session, global-admin flag).

---

## Findings & fixes

Severity legend: 🔴 high · 🟠 medium · 🟡 low/info · 🟢 strength (already fine).

### Applied during this audit

| ID | Severity | Area | Issue | Fix |
|---|---|---|---|---|
| F-01 | 🟠 | Availability | `/submit` had no rate limit, enabling report-flooding that exhausts storage/e-mail. | Added `throttle:10,1` (10 req/min/IP). |
| F-02 | 🟠 | Information disclosure | `/report?...Token=` had no rate limit, offering online UUID guessing (infeasible but still). | `throttle:60,1` on GET+POST `/report`. |
| F-03 | 🟡 | Information disclosure | `/file/{name}?id=…` same concern. | `throttle:60,1`. |
| F-04 | 🟠 | Dev-login exposure | Dev login activated on any non-production APP_ENV – a single misconfigured env var exposed it in staging. | Requires **both** non-production env **and** explicit `MELDE_DEV_LOGIN_ENABLED=true`. Defaults to false. Also `throttle:5,1`. |
| F-05 | 🟠 | Defense-in-depth (XSS) | No `Content-Security-Policy` header. | CSP added to `SecurityHeaders` middleware. Blocks inline `<object>`, unknown scripts, iframe embedding. |
| F-06 | 🟠 | Transport security | No `Strict-Transport-Security`. | HSTS (1 year, `includeSubDomains`) added when the request is served over HTTPS. |
| F-07 | 🟡 | Privacy | `robots.txt` allowed indexing of `/report?...Token=…` and admin surfaces. | Broadened `Disallow` to `/report`, `/file/`, `/dev/`, `/api/`, `/newTopic/`, `/reports/`, `/saml/`, `/shib`. |
| F-08 | 🟡 | Deprecation | Deprecated `X-XSS-Protection` header was set – actively harmful in some browsers. | Removed. Replaced by CSP. |
| F-09 | 🟡 | Defense-in-depth | `Permissions-Policy` didn't cover the FLoC opt-out. | Added `interest-cohort=()`. |
| F-10 | 🟡 | Session hardening | `.env.example` didn't surface `SESSION_SECURE_COOKIE` / `SAME_SITE` / `HTTP_ONLY`. | Added explicitly with safe defaults and a reminder to flip `SESSION_SECURE_COOKIE=true` in production. |

All ten fixes are in the tree; Pint + PHPStan level 9 + 51-test suite stay
green afterwards.

### Remaining recommendations (not code)

| ID | Severity | Area | Recommendation |
|---|---|---|---|
| R-01 | 🟠 | Transport | Terminate TLS in front of the app (Traefik / nginx / Caddy). Verify `APP_URL=https://…` so `URL::forceScheme('https')` kicks in and HSTS is emitted. |
| R-02 | 🟠 | Dependency hygiene | Run `composer audit` (or `composer outdated -D`) as part of the CI pipeline on a cron; today advisories are only checked at `composer install` time. |
| R-03 | 🟠 | Secret management | `SAML2_SP_PRIVATEKEY` is read from env. On shared hosting, prefer mounting the key as a file (root-only) and loading it via `file_get_contents()`. |
| R-04 | 🟠 | SSRF (by design) | `matrix.homeServer` and `webhook.target` let a topic admin trigger outbound HTTP from the server to an arbitrary URL. Mitigate with egress policy (VLAN/FW) or a central allowlist when infrastructure permits. |
| R-05 | 🟡 | Backups | Uploaded files live under `storage/app/uploads`; their filenames are UUIDs so enumeration is infeasible, but backup media still need access control. |
| R-06 | 🟡 | Log redaction | `Log::error('…', ['error' => $e->getMessage()])` may include SMTP server details. Consider adding a log scrubber for production. |
| R-07 | 🟡 | Fail2ban | Beyond Laravel's in-app rate limiter, a network-layer rate limit on `/submit` would survive a Laravel container restart. |
| R-08 | 🟡 | SAML cert rotation | Document a rotation procedure (generate new SP keypair → register with DFN-AAI → swap → restart). Today it's ad-hoc. |
| R-09 | 🟡 | Admin audit log | Topic edits and status changes are silent – no append-only log of who did what. Consider an `admin_events` table for forensics. |

---

## Threat-by-threat walkthrough

### Authentication (SAML)

- `POST /shib` ACS endpoint uses `onelogin/php-saml 4.x`, validates the
  assertion signature, checks NotBefore/NotOnOrAfter, enforces our
  `entityId`, and pulls `uid` / `displayName` / `mail` from the friendly
  attribute set.
- On failure it calls `abort(403)` – no stack trace, no SAML error leakage
  to the user.
- CSRF is correctly excluded from `/shib` and `/saml/*` (the signed SAML
  response is the credential).
- 🟢 Assertion replay is blocked by `php-saml`'s in-memory nonce store;
  we don't attempt to layer a second check.

**Caveat:** the SP keypair in `.env` is only as safe as your secret store.
See R-03.

### Authorisation

- `EnsureGlobalAdmin` consults `config('meldeplattform.admin_users')` –
  trimmed, string-filtered list.
- `EnsureTopicAdmin` accepts global admins *or* matches on `Topic::isAdmin($uid)`.
- `/newTopic/0` can only be reached by global admins (a topic-admin of
  some other topic is rejected because there's no "topic 0" to be admin of).
- Token access to `/report` is strictly equality-keyed to the two opaque
  128-bit UUIDv4s stored on the report row. No sequential IDs are reachable.
- 🟢 No IDOR surfaces via integer IDs: every protected route is either
  admin-gated or token-gated.

### Input validation

- `/submit` goes through `SubmitReportRequest` (validates `topic` exists,
  `email` is valid-rfc). Dynamic field presence is validated per-field in
  the controller.
- `/api/topic/{id}` goes through `UpsertTopicRequest` (strict allowlist on
  field `Type`, exhaustive type rules).
- `$request->string/integer` used throughout – no raw `(string) $req->input()`.
- 🟢 Eloquent is the only DB access path → no SQL-injection surface.

### File uploads

- Extension allowlist lives in `config/meldeplattform.allowed_extensions`.
- Filename passes through `basename()` → no path traversal.
- On disk we never use the client filename; the stored name is `{uuid}.{ext}`.
- Upload size is bound 3× (FormRequest `max`, per-file check `max_upload_mb`,
  nginx `client_max_body_size`).
- Download endpoint resolves `realpath()` and verifies the target is inside
  the `uploads` disk root before serving it.
- 🟢 A confused-deputy download (serving /etc/passwd) is unreachable –
  the `File` row's `location` column is only ever written by the submit
  handler, and the runtime check still guards against a misconfiguration
  or a DB tampering.

### CSRF / sessions

- Laravel's `PreventRequestForgery` middleware is active for every POST
  except `/shib` and `/saml/*` (signed SAML responses).
- Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` flag flips to true
  when `SESSION_SECURE_COOKIE=true` (set this in production).
- 🟢 `APP_KEY` is mandatory for session encryption; the Docker entrypoint
  generates one on first boot if `.env` has an empty `APP_KEY`.

### XSS

- All dynamic data is rendered via Blade's `{{ }}` which auto-escapes.
- `{!! … !!}` is only used on:
  - `Message::renderedBody()` — goes through HTML-escape → CommonMark →
    HTMLPurifier (`meldeplattform` profile: b, strong, i, em, br, p, ul,
    ol, li, a[href], code, pre, blockquote).
  - `Markdown::renderOperatorContent()` — same pipeline, wider tag set
    for trusted imprint/privacy markdown.
- HTMLPurifier `URI.AllowedSchemes` restricts links to http/https/mailto.
- CSP removes the remaining inline-script/style risk for any gap.

### SSRF

- `MatrixMessenger` and `WebhookMessenger` do outbound HTTP to targets
  set by **topic admins** (not by anonymous reporters). Risk is limited
  to a trusted-admin-gone-rogue scenario. See R-04.
- Matrix messages use `Http::timeout(10)` – no indefinite hangs.
- 🟢 Reporters cannot set these URLs.

### Email

- `ReportNotification` mails are built via Laravel's `Mailable` + `Mail::to()`
  – PHPMailer/Symfony-Mailer handles header escaping. No `\r\n` injection
  route for a reporter-supplied email address (re-validated with
  `FILTER_VALIDATE_EMAIL` in `ReportController::reply()` before use).
- The outbound subject line contains `$topic->name('en')` – admin-supplied
  text, bounded in length by column type, rendered into a raw string but
  again escaped by Symfony-Mailer.

### Information disclosure

- Error messages are short (`invalid email format`, `file too large`) and
  don't leak internal state.
- `APP_DEBUG=true` must be flipped to `false` in production; Docker compose
  defaults to `true` for convenience (local dev).
- 🟢 No stack traces in non-debug mode; `Ignition` is dev-only (not bound
  when `APP_DEBUG=false`).

### Transport security

- Behind Traefik / nginx with Let's Encrypt the whole app is HTTPS-only.
- `AppServiceProvider` calls `URL::forceScheme('https')` when `APP_URL` is
  `https://…` – link-generation stays consistent behind a TLS terminator.
- HSTS emitted only on HTTPS requests (so local `http://localhost:8080`
  still works without the browser pinning to HTTPS).

### Dependency surface

```
laravel/framework      13.5.0
onelogin/php-saml       4.1.x
league/commonmark       2.5.x
mews/purifier           3.4.x  (wraps ezyang/htmlpurifier)
guzzlehttp/guzzle       7.9.x
```

PHPUnit advisories: we pass `--no-security-blocking` at install time to
work around the still-unfixed transitive PHPUnit advisory tracked by
Composer. The vulnerable code path is dev-only (test runner) and is never
reached by the running app.

---

## How we verified

```bash
docker compose up -d
docker compose exec app composer install
docker compose exec app vendor/bin/pint --test       # style
docker compose exec app vendor/bin/phpstan analyse   # static analysis, level 9
docker compose exec app vendor/bin/phpunit           # 51 tests, 118 assertions
curl -sI http://localhost:8080/ | grep -i security   # headers sanity-check
```

All four must stay green on `main`. The GitHub Actions workflow in
`.github/workflows/ci.yml` enforces this on every push/PR.

---

## Responsible disclosure

If you find a vulnerability, please e-mail the TUM-Dev security contact
listed in the repository's [README](README.md). Do not open a public
GitHub issue for security bugs.
