# Security Policy

## Supported versions

Security fixes are applied to the latest release on the `main` branch.
Older tags do not receive backports.

| Version | Supported |
|---|---|
| `main` (latest) | ✅ |
| Earlier tags | ❌ |

## Reporting a vulnerability

Please report security issues **privately** by e-mail to:

**it-sicherheit@tum.de**

Do **not** open a public GitHub issue, pull request, or discussion for
security bugs.

When reporting, please include as much of the following as you can:

- A description of the issue and its potential impact.
- Steps to reproduce (PoC, request/response traces, affected URL or route).
- Affected version / commit hash.
- Your environment (PHP version, browser, OS) if relevant.
- Whether the issue is already publicly known or has been disclosed
  elsewhere.

If you would like your report to be encrypted, please request a PGP key
in your initial plaintext e-mail.

## Response process

- **Acknowledgement:** we aim to acknowledge receipt within **3 working days**.
- **Initial assessment:** within **10 working days** we will confirm the
  issue, ask for clarifications, or let you know that we could not
  reproduce it.
- **Fix & disclosure:** for confirmed issues we will coordinate a patch
  and a disclosure timeline with you. We prefer coordinated disclosure
  and typically aim for a fix within **90 days** of the initial report,
  earlier for high-severity findings.
- **Credit:** with your consent, we are happy to credit you in the
  release notes once a fix is published.

## Scope

In scope:

- The source code in this repository.
- The default Docker setup shipped with this repository.
- Documented configuration options in `.env.example` and `config/`.

Out of scope:

- Vulnerabilities in third-party dependencies without a demonstrated
  impact on this application (please report those to the upstream
  project; we track advisories via Composer / Renovate).
- Issues that require a compromised admin account, a compromised SAML
  IdP, or physical access to the host.
- Findings against deployments that have disabled the built-in security
  middleware or that run in `APP_DEBUG=true` in production.
- Denial-of-service via volumetric traffic, brute-forcing rate-limited
  endpoints, or resource-exhaustion attacks that require privileged
  network position.
- Best-practice / hardening suggestions without a concrete
  vulnerability (welcome as regular GitHub issues / PRs instead).

## Safe harbor

We will not pursue legal action against researchers who:

- Make a good-faith effort to follow this policy.
- Avoid privacy violations, service degradation, and data destruction.
- Only interact with accounts they own or have explicit permission to
  access.
- Give us a reasonable amount of time to remediate before any public
  disclosure.

## Security hardening built in

For operators, the repository ships with:

- CSRF protection on all state-changing routes (except the signed
  SAML ACS).
- CSP, HSTS (on HTTPS), `X-Frame-Options`, `Referrer-Policy` and
  `Permissions-Policy` headers via the `SecurityHeaders` middleware.
- Rate limiting on `/submit`, `/report`, `/file`, `/dev/login`, and the
  SAML endpoints.
- Markdown rendered through CommonMark + HTMLPurifier with a strict
  tag/URL-scheme allowlist.
- File uploads with extension allowlist, UUID storage names, and
  path-traversal checks.
- Dev login disabled unless both `APP_ENV != production` **and**
  `MELDE_DEV_LOGIN_ENABLED=true` are set.

See [`README.md`](README.md) for deployment notes and the configuration
reference.
