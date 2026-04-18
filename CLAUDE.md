# CLAUDE.md

Project-specific guidance for Claude Code (or any LLM coding agent) working
on the **TUM Meldeplattform** repo.

## Repo identity

- **Language:** PHP 8.3, Laravel 13
- **DB:** MySQL / MariaDB (SQLite in tests)
- **Auth:** SAML via `onelogin/php-saml` (TUM Shibboleth IdP)
- **Legacy:** the original Go implementation lives under `_legacy/` – read
  for domain context, never edit.

## First things to do in a new session

```bash
docker compose up -d                       # app on :8080, db on :3306
docker compose exec app composer install   # one-time after checkout
```

All tooling runs **inside the container** — the host may not have PHP at all.

```bash
docker compose exec app vendor/bin/pint --test     # style check
docker compose exec app vendor/bin/pint            # autofix style
docker compose exec app vendor/bin/phpstan analyse # static analysis, level 9
docker compose exec app vendor/bin/phpunit         # test suite
```

`docker-compose.override.yml` bind-mounts the repo into the container, so
edits on the host apply immediately; `vendor/` stays in a container volume
for filesystem speed on macOS.

## Hard conventions

**Types.** PHPStan at level 9 is enforced in CI. That implies:

- Use `$request->string('foo', '')->toString()` / `$request->integer('foo')` /
  `$request->boolean('foo')` instead of `(string) $request->input(...)`.
- Use `Config::string()`, `Config::integer()`, `Config::array()` instead of
  raw `config(...)` when a scalar is expected. Cast arrays with
  `array_values(array_filter(..., 'is_string'))` when you need `list<string>`.
- Write Eloquent models with `@property` / `@property-read` annotations for
  columns and relations. Add `/** @return HasMany<Field, $this> */` on each
  relation method.

**Style.** Laravel Pint (Laravel preset plus extras in `pint.json`). Run
`pint` before committing. Single quotes, short array syntax, trailing
commas in multiline arrays, `declare_strict_types` **off**.

**Validation.** Use `FormRequest` classes for anything with more than one
or two rules – see `app/Http/Requests/{SubmitReportRequest,UpsertTopicRequest}.php`
for the shape. Controllers never call `$request->validate()` inline.

**Tests.** Extend `Tests\TestCase`; it already disables CSRF for the suite
(`PreventRequestForgery`) and pins the global-admin UID to `globaladmin`.
Use `asGlobalAdmin()` / `asUser($uid)` in `AdminApiTest`-style tests to set
the SAML session. Use `postJson()` when asserting 422 validation responses.

## Things NOT to reintroduce

- **Custom XSRF tokens.** The Go original had a hand-rolled `xsrf.{token}`
  session bag; we dropped it in favour of Laravel's CSRF middleware. Don't
  add it back.
- **`App\Support\Cfg`.** This helper used to wrap `config()` returning `mixed`
  – Laravel 11+ has `Config::string/integer/array` natively. Deleted on
  purpose.
- **A `cache` or `sessions` DB migration.** We run with file-based drivers
  on shared hosting. Don't add them unless you also switch the driver.
- **`laravel/tinker` in `require`.** It's `dev`-only at best; currently it
  has no Laravel-13-compatible release so it's not even listed. Don't
  re-pin something that'll block `composer update`.

## Architectural touch points

- **Routing:** `routes/web.php` – rate limits live here (`throttle:*`).
  The dev-login block is gated by `APP_ENV != production` **and**
  `config('meldeplattform.dev_login_enabled')`. Don't loosen.
- **Middleware pipeline:** `bootstrap/app.php` appends `SecurityHeaders`,
  `LocaleMiddleware`, `ShareViewData` to the `web` group, and registers
  `topic.admin` / `admin` aliases.
- **SAML:** `SamlController` uses onelogin/php-saml directly. The Go
  version's `/saml/metadata`, `/saml/out`, `/saml/logout`, `/saml/slo`,
  `/shib` routes are mirrored 1:1.
- **Reports pseudonymity:** a report has two tokens, `reporter_token` and
  `administrator_token`, both UUIDv4, both `unique` indexed. The `report`
  endpoint resolves strictly on equality – don't invent "friendly" lookups.
- **Messengers:** `app/Services/Messengers/*` implement `Messenger` and
  are dispatched by `MessengerDispatcher::forTopic($topic)`. Add a new one
  by implementing the interface and appending to the topic's `contacts`
  JSON. Configuration is **admin-provided** → any new Messenger must not
  blindly follow redirects or accept non-HTTPS URLs by default.
- **Markdown:** `App\Support\Markdown::sanitize()` is the only path for
  user-supplied markdown. It html-escapes → CommonMark → HTMLPurifier.
  Never bypass it. `renderOperatorContent()` is the variant for trusted
  imprint / privacy markdown and allows a wider tag set.

## Security posture

Before shipping a change, check `SECURITY.md`. The short version:

- Every POST must go through CSRF **or** be explicitly listed in
  `preventRequestForgery(except: [...])` in `bootstrap/app.php`.
- Any new endpoint that creates DB rows, sends e-mails, or returns
  user-addressable content **must** add a `throttle:*,1` middleware.
- Any new outbound HTTP call needs a timeout (`Http::timeout(10)`) and
  lives behind an admin-configured URL, not a reporter-supplied one.
- Any new HTML context in Blade needs either `{{ }}` (escape) or the
  `Markdown` pipeline (sanitize).

## CI

- `.github/workflows/ci.yml` runs `pint --test`, `phpstan analyse`, and the
  PHPUnit suite against both SQLite and MariaDB.
- `.github/workflows/publish.yml` builds & publishes the Docker image on
  pushes to `main`.

Both must pass on every PR. If a change breaks PHPStan level 9, fix the
types (don't ratchet the level down).

## Memory notes for Claude

The user is comfortable with:

- Full-surface refactors (see the Go → Laravel port → Laravel 13 upgrade
  → level-9 hardening history).
- Opinionated, framework-idiomatic solutions (Laravel built-ins > custom
  helpers).
- Docker-first development loops.

The user prefers:

- Concise status updates with tool output snapshots (Pint/PHPStan/PHPUnit
  tail).
- Direct German when pinged in German; otherwise English.
- Issue-then-fix progression – no "here's a 30-item plan, pick one" when
  the fix is obvious.

Do **not**:

- Run destructive git operations without asking.
- Ship a change that can't be run through `pint && phpstan && phpunit` cleanly.
- Reintroduce dev-time shortcuts in production-facing code paths
  (e.g. unlocking the dev-login bypass by removing the env gate).
