# TUM Meldeplattform

Anonyme Meldeplattform der Technischen Universität München. Laravel-basierte
Portierung der ursprünglichen Go-Anwendung mit MySQL-Backend, TUM-Design und
SAML-Login über den TUM Shibboleth-IdP.

<p align="center">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13.5-ff2d20?logo=laravel&logoColor=white">
  <img alt="PHP 8.3" src="https://img.shields.io/badge/PHP-8.3-777bb4?logo=php&logoColor=white">
  <img alt="PHPStan level 9" src="https://img.shields.io/badge/PHPStan-level%209-1d4ed8">
  <img alt="Tests" src="https://img.shields.io/badge/Tests-51%20passing-16a34a">
  <img alt="License" src="https://img.shields.io/badge/License-MIT-blue">
</p>

---

## Inhalt

- [Features](#features)
- [Stack](#stack)
- [Schnellstart (Docker)](#schnellstart-docker)
- [Dev-Login](#dev-login)
- [Shared-Hosting-Deployment](#shared-hosting-deployment)
- [Konfiguration](#konfiguration)
- [Entwicklung](#entwicklung)
- [Tests & Qualität](#tests--qualität)
- [Sicherheit](#sicherheit)
- [Lizenz](#lizenz)

---

## Features

- **Anonyme Meldungen** pro Thema (Topic) mit frei konfigurierbaren Feldern
  (Text, Textarea, Select, Checkbox, Datei, mehrere Dateien, Datum, E-Mail).
- **Zwei-Wege-Kommunikation** via pseudonymer Tokens – kein Account nötig.
  Eine Meldung hat einen Reporter- und einen Administrator-Token (UUIDv4).
- **Optionale Kontakt-E-Mail** für Update-Benachrichtigungen an Melder*innen.
- **Dateiupload** mit Erweiterungs-Allowlist, UUID-Speichernamen,
  3-facher Größenbegrenzung und Path-Traversal-Schutz.
- **Benachrichtigungen** pro Thema über E-Mail, Matrix und Webhook
  (konfigurierbar via JSON-Spalte `topics.contacts`).
- **SAML-SSO** zum TUM Shibboleth-IdP mit Attribut-Mapping für
  `uid` / `displayName` / `mail`.
- **Mehrsprachig** (DE/EN) auf Basis von Laravel-Translations.
- **Markdown** mit strikter HTMLPurifier-Sanitizing-Pipeline.
- **TUM-Design** in reinem CSS – keine Build-Toolchain nötig für Shared
  Hosts.

## Stack

| Bereich | Komponente |
|---|---|
| Framework | Laravel 13 |
| PHP | 8.3 |
| DB | MySQL 5.7+ / MariaDB 10.6+ (SQLite :memory: in Tests) |
| SAML | `onelogin/php-saml` |
| Markdown | `league/commonmark` + `mews/purifier` |
| HTTP (Matrix/Webhook) | Guzzle via Laravel `Http` Facade |
| Qualität | Laravel Pint, PHPStan (larastan) Level 9, PHPUnit 11 |
| CI | GitHub Actions (lint, stan, tests × SQLite + MariaDB) |
| Container | PHP-FPM + nginx + Supervisord, MariaDB 11 via Compose |

## Schnellstart (Docker)

Voraussetzung: Docker Desktop ≥ 24.

```bash
git clone https://github.com/TUM-Dev/meldeplattform.git
cd meldeplattform
cp .env.example .env
docker compose up --build -d
```

- App unter http://localhost:8080
- MariaDB als Service `db`
- Composer-Dependencies, `APP_KEY`, Migrations werden vom Entrypoint
  automatisch beim ersten Start gesetzt bzw. ausgeführt

**Fresh Start**

```bash
docker compose down -v        # DB + Uploads + Logs weg
docker compose up --build -d
```

**Logs**

```bash
docker compose logs -f app
```

## Dev-Login

Für lokales Debuggen ohne Shibboleth gibt es einen Dev-Login unter
`/dev/login`, der direkt einen SAML-Session-User setzt. Er ist doppelt
abgesichert:

1. `APP_ENV != "production"`
2. `MELDE_DEV_LOGIN_ENABLED=true` in `.env`

In `docker-compose.yml` ist das für lokale Dev-Nutzung auf `true`
vorbelegt. In Produktion unter keinen Umständen aktivieren.

Als Kurzschluss-Login stehen die in `MELDE_ADMIN_USERS` konfigurierten
UIDs zur Auswahl (Default im Compose-Setup: `dev,ge25bof`).

## Shared-Hosting-Deployment

Die App ist so gebaut, dass sie auch auf klassischen PHP-Hostings
(Apache + `mod_rewrite` oder nginx mit Laravel-Rewrite, PHP-FPM, MySQL,
**kein** Shell-Zwang) läuft.

**Minimal-Voraussetzungen**

- PHP ≥ 8.3 mit Extensions: `pdo_mysql`, `mbstring`, `openssl`,
  `tokenizer`, `xml`, `ctype`, `json`, `zip`, `curl`, `fileinfo`, `gd`,
  `intl`, `bcmath`
- MySQL / MariaDB
- Möglichkeit, den DocumentRoot auf den Unterordner `public/` zu zeigen

**Schritt-für-Schritt**

1. Dependencies lokal installieren und alles hochladen:
   ```bash
   composer install --no-dev --optimize-autoloader --no-security-blocking
   rsync -av --exclude node_modules --exclude _legacy . user@host:/var/www/meldeplattform/
   ```
2. DocumentRoot auf `public/` setzen. Falls nicht möglich: Inhalt von
   `public/` in den Webroot verschieben und in `public/index.php` die
   Pfade zu `../vendor/autoload.php` bzw. `../bootstrap/app.php`
   anpassen.
3. `.env` auf dem Server erstellen (aus `.env.example` ableiten),
   insbesondere:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://<domain>
   APP_KEY=base64:...             # einmalig: php artisan key:generate
   SESSION_SECURE_COOKIE=true
   DB_*
   MAIL_*
   SAML2_*
   MELDE_ADMIN_USERS=ge42tum,ge25bof
   MELDE_DEV_LOGIN_ENABLED=false
   ```
4. Einmalig (Shell oder Artisan-Webrunner):
   ```bash
   php artisan key:generate        # nur wenn APP_KEY leer
   php artisan migrate --force
   php artisan storage:link
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
5. Rechte:
   ```bash
   chmod -R 775 storage bootstrap/cache
   ```
6. (Optional) Cron für `schedule:run`:
   ```
   * * * * * cd /home/USER/meldeplattform && php artisan schedule:run >/dev/null 2>&1
   ```

## Konfiguration

### SAML

In `config/saml2.php` konfiguriert, Werte per `.env`.

- `POST /shib` – Assertion Consumer Service (muss im IdP hinterlegt sein)
- `GET /saml/metadata` – SP-Metadaten
- `GET /saml/out` – Login-Initiation
- `GET /saml/logout` – lokaler Logout
- `POST /saml/slo` – Single-Logout-Response vom IdP

Attribute-Mapping: `uid`, `displayName`, `mail`.

### Admins

Globale Admins via `MELDE_ADMIN_USERS` (komma-separierte UIDs). Sie dürfen
neue Topics anlegen und sehen jede Meldung.

Topic-Admins werden pro Topic im Admin-UI gepflegt (`/newTopic/{id}`).
Sie dürfen ihr Topic bearbeiten, alle Meldungen dazu sehen und beantworten.

### Topics & Messenger

Jedes Topic hat:

- Mehrsprachige `Name` / `Summary` (DE/EN)
- Beliebige Felder mit Typen
  `text | textarea | select | checkbox | file | files | email | date | number | url`
- Eine Kontakt-E-Mail (Spalte `topics.email`)
- Optionale weitere Messenger in der JSON-Spalte `topics.contacts`:

```json
{
  "email":   { "target": "it-sec@tum.de" },
  "matrix":  { "homeServer": "matrix.tum.de", "roomID": "!abc:tum.de", "accessToken": "…" },
  "webhook": { "target": "https://hook.example/endpoint" }
}
```

Versand erfolgt über `App\Services\MessengerDispatcher` – Mail via Laravel
Mailable, Matrix via HTTP Client, Webhook via HTTP Client.

## Entwicklung

```bash
# Im laufenden Container – der Host braucht kein PHP
docker compose exec app composer install
docker compose exec app vendor/bin/pint            # Autoformat
docker compose exec app vendor/bin/phpstan analyse # Statik, Level 9
docker compose exec app vendor/bin/phpunit         # 51 Tests
```

Das `docker-compose.override.yml` bind-mounted das Repo in den Container,
sodass Edits auf dem Host sofort wirken.

**Routing**

```
GET  /                          Home (Themenliste)
GET  /form/{topicID}            Meldeformular
POST /submit                    Meldung absenden (throttle 10/min)
GET  /report?reporterToken=…    Meldung als Melder*in sehen + antworten
POST /report?...Token=…         Antworten (throttle 60/min)
GET  /file/{name}?id={uuid}     Datei-Download (throttle 60/min)
GET  /imprint, /privacy         statische Markdown-Seiten
GET  /setLang?lang=de|en        Sprach-Cookie setzen
GET  /newTopic/{id}             Topic anlegen/bearbeiten (admin)
GET  /reports/{id}              Reports zu einem Topic (admin)
POST /api/topic/{id}            Topic upsert (admin, JSON)
POST /api/topic/{t}/report/{r}/status  Status wechseln (admin)
GET  /saml/metadata             SP-Metadaten
GET  /saml/out                  Login starten
POST /shib                      SAML ACS
GET  /dev/login                 Dev-Bypass (nur wenn aktiviert)
GET  /up                        Health-Check
```

## Tests & Qualität

```bash
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/phpunit
```

- **Pint**: Laravel-Preset + Extras (siehe `pint.json`)
- **PHPStan**: Level 9 (siehe `phpstan.neon`, larastan-Extension)
- **PHPUnit**: 51 Tests (Unit + Feature), 118 Assertions

GitHub Actions führt die drei Stufen bei jedem Push/PR aus – Test-Matrix
gegen SQLite + MariaDB 11.

## Sicherheit

Siehe [`SECURITY.md`](SECURITY.md) für den vollständigen Audit-Report
(Threat Model, Findings, Fixes, Empfehlungen).

Kurzfassung:

- CSRF: aktiv auf allen POST-Routen außer `/shib` + `/saml/*`.
- CSP, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` im
  `SecurityHeaders`-Middleware; HSTS bei HTTPS-Requests.
- Rate Limiting (`throttle:*,1`) auf `/submit`, `/report`, `/file`,
  `/dev/login`, `/saml/out`, `/shib`.
- Markdown: escape → CommonMark → HTMLPurifier, restriktive
  Tag-Allowlist.
- Dev-Login nur mit `APP_ENV != production` **und**
  `MELDE_DEV_LOGIN_ENABLED=true`.
- SAML-ACS-Endpoint validiert Signatur und NotBefore/NotOnOrAfter via
  `onelogin/php-saml`.

Security-Meldungen bitte an die TUM-Dev-Security-Adresse, **nicht** über
öffentliche GitHub-Issues.

## Lizenz

[MIT](LICENSE). Historischer Go-Quellcode unter [`_legacy/`](_legacy/).
