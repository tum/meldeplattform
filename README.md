# TUM SafeSignal

**Whistleblowing & IT Security Reporting System** der Technischen Universität
München. Anonyme, SAML-gestützte Meldeplattform mit Laravel-Backend, MySQL,
TUM-Design und SAML-Login über den TUM Shibboleth-IdP.

> Interne Paket- und Verzeichnisnamen (`tum-dev/meldeplattform`, Config-Key
> `meldeplattform.*`, `MELDE_*` Env-Vars, DB-Name `meldeplattform`) bleiben
> aus Kompatibilitätsgründen erhalten. Nach außen heißt das System
> **TUM SafeSignal**.

<p align="center">
  <img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13.5-ff2d20?logo=laravel&logoColor=white">
  <img alt="PHP 8.3" src="https://img.shields.io/badge/PHP-8.3-777bb4?logo=php&logoColor=white">
  <img alt="PHPStan level 9" src="https://img.shields.io/badge/PHPStan-level%209-1d4ed8">
  <img alt="Tests" src="https://img.shields.io/badge/Tests-165%20passing-16a34a">
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
- [Geplante Tasks (Cron)](#geplante-tasks-cron)
- [Entwicklung](#entwicklung)
- [Tests & Qualität](#tests--qualität)
- [Sicherheit](#sicherheit)
- [Danksagung](#danksagung)
- [Lizenz](#lizenz)

---

## Features

- **Anonyme Meldungen** pro Thema (Topic) mit frei konfigurierbaren Feldern
  (Text, Textarea, Select, Checkbox, Datei, mehrere Dateien, **Sprachnachricht**,
  Datum, E-Mail, Zahl, URL).
- **Zwei-Wege-Kommunikation** via pseudonymer Tokens – kein Account nötig.
  Eine Meldung hat einen Reporter- und einen Administrator-Token (UUIDv4).
- **Wiedereinstieg per Eingangscode**: 16-stelliger Code (nur als HMAC
  gespeichert, Klartext nie persistiert) für anonyme Rückkehr unter `/track`.
- **Mündliche Meldung (Sprachnachricht)**: Audio-Feldtyp mit In-Browser-Aufnahme
  (MediaRecorder) oder Upload – erfüllt die Mündlichkeits-Anforderung der
  EU-Whistleblowing-Richtlinie (Art. 9 Abs. 2) / HinSchG § 16.
- **Fristen nach EU-Richtlinie / HinSchG § 17**: Eingangsbestätigung (7 Tage)
  und Rückmeldung (3 Monate) werden getrackt, im Dashboard markiert und per
  täglichem `reports:remind` an die Bearbeiter*innen erinnert.
- **Datenaufbewahrung / Löschung**: Pro-Topic- bzw. globale Aufbewahrungsfrist
  (Default 3 Jahre, HinSchG § 11 Abs. 5). `reports:prune` löscht abgeschlossene
  Meldungen samt Anhängen ab dem Abschlussdatum (`closed_at`).
- **CSV-Export** der (gefilterten) Dashboard-Meldungen für Audits/Reporting –
  nur Fall-Metadaten, keine Meldeinhalte; der Export wird auditiert.
- **Append-only Audit-Log** (`/audit`) für sicherheitsrelevante Admin-Aktionen,
  ohne Melder*innen-PII oder Meldeinhalte.
- **Optionale Kontakt-E-Mail** für Update-Benachrichtigungen an Melder*innen.
- **Dateiupload** mit Erweiterungs-Allowlist, UUID-Speichernamen,
  3-facher Größenbegrenzung, Path-Traversal-Schutz und EXIF-/Metadaten-Stripping
  bei Rasterbildern.
- **Benachrichtigungen** pro Thema über E-Mail und Webhook (HTTPS-only,
  HMAC-signiert; konfigurierbar via JSON-Spalte `topics.contacts`).
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
| HTTP (Webhook) | Guzzle via Laravel `Http` Facade |
| Qualität | Laravel Pint, PHPStan (larastan) Level 9, PHPUnit 12 |
| CI | GitHub Actions (lint, stan, tests × SQLite + MariaDB) |
| Container | PHP-FPM + nginx + Supervisord, MariaDB 11 via Compose |

## Schnellstart (Docker)

Voraussetzung: Docker Desktop ≥ 24.

```bash
git clone https://github.com/tum/meldeplattform.git
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
6. Cron für den Scheduler einrichten (nötig für Fristen-Erinnerungen und
   Daten-Löschung – siehe [Geplante Tasks (Cron)](#geplante-tasks-cron)):
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

#### IdP-Zertifikat importieren

Das Signing-Zertifikat des IdPs kann direkt aus dessen Metadaten gezogen
werden, statt es manuell aus der XML zu kopieren:

```bash
# Dry run – zeigt Cert + SSO/SLO-URLs + SHA-256-Fingerprint
docker compose exec app php artisan saml:import-idp-metadata

# Schreibt SAML2_IDP_X509CERT / SAML2_IDP_SSO_URL / SAML2_IDP_SLO_URL /
# SAML2_IDP_ENTITYID direkt in die .env (fragt vor dem Überschreiben nach).
docker compose exec app php artisan saml:import-idp-metadata --write
```

Standardmäßig wird `SAML2_IDP_METADATA_URL` (z. B.
`https://login.tum.de/idp/shibboleth`) abgefragt; `--url=` und
`--entity-id=` überschreiben die Defaults. Der TLS-Peer wird **immer**
gegen die lokalen Root-CAs validiert. Den ausgegebenen SHA-256-Fingerprint
bitte **out-of-band** gegen eine vertrauenswürdige Quelle (TUM-Support,
Föderations-Registry) vergleichen, bevor der Cert in Produktion
übernommen wird.

Bei IdP-Key-Rollover denselben Command erneut laufen lassen.

### Admins

Globale Admins via `MELDE_ADMIN_USERS` (komma-separierte UIDs). Sie dürfen
neue Topics anlegen und sehen jede Meldung.

Topic-Admins werden pro Topic im Admin-UI gepflegt (`/newTopic/{id}`).
Sie dürfen ihr Topic bearbeiten, alle Meldungen dazu sehen und beantworten.

### Topics & Messenger

Jedes Topic hat:

- Mehrsprachige `Name` / `Summary` (DE/EN)
- Beliebige Felder mit Typen
  `text | textarea | select | checkbox | file | files | audio | email | date | number | url`
  (`audio` = Sprachnachricht/mündliche Meldung, eigene Audio-Allowlist)
- Eine Kontakt-E-Mail (Spalte `topics.email`)
- Optionale Aufbewahrungsfrist (`topics.retention_days`); ohne Wert greift der
  globale Default (`MELDE_DEFAULT_RETENTION_DAYS`)
- Optionale weitere Messenger in der JSON-Spalte `topics.contacts`:

```json
{
  "email":   { "target": "it-sec@tum.de" },
  "webhook": { "target": "https://hook.example/endpoint" }
}
```

Versand erfolgt über `App\Services\MessengerDispatcher` – Mail via Laravel
Mailable, Webhook via HTTP Client.

### Fristen, Erinnerungen & Aufbewahrung

Per `.env` steuerbar (Defaults in Klammern):

| Env-Var | Default | Bedeutung |
|---|---|---|
| `MELDE_ACK_DEADLINE_DAYS` | `7` | Frist für die Eingangsbestätigung (EU-RL Art. 9 / HinSchG § 17) |
| `MELDE_FEEDBACK_DEADLINE_DAYS` | `90` | Frist für die Rückmeldung (≈ 3 Monate) |
| `MELDE_REMINDER_ACK_LEAD_DAYS` | `2` | Vorlauf, ab dem `reports:remind` vor der Bestätigungsfrist erinnert |
| `MELDE_REMINDER_FEEDBACK_LEAD_DAYS` | `14` | Vorlauf vor der Rückmeldefrist |
| `MELDE_DEFAULT_RETENTION_DAYS` | `1095` | Globale Aufbewahrung in Tagen (3 Jahre, HinSchG § 11 Abs. 5); `0` = nur Pro-Topic-Frist nutzen |
| `MELDE_MAX_UPLOAD_MB` | `10` | Max. Größe pro Datei-/Audio-Upload |
| `MELDE_WEBHOOK_SECRET` | – | Shared Secret zum HMAC-Signieren ausgehender Webhooks (`X-SafeSignal-Signature`) |

Aufbewahrung wird ab dem **Abschluss** einer Meldung gemessen
(`reports.closed_at`, gesetzt beim Wechsel auf *Erledigt*/*Spam*) – offene
Verfahren werden nie automatisch gelöscht. Audio-Uploads werden **nicht**
metadaten-bereinigt (nur Rasterbilder); Melder*innen werden im UI gewarnt.

## Geplante Tasks (Cron)

Zwei Artisan-Commands sind im Scheduler registriert (`routes/console.php`):

| Command | Zeitplan | Zweck |
|---|---|---|
| `reports:prune` | täglich | Löscht abgeschlossene Meldungen samt Anhängen nach Ablauf der Aufbewahrungsfrist |
| `reports:remind` | täglich 07:00 (Europe/Berlin) | Erinnert Bearbeiter*innen per E-Mail an Meldungen nahe/über einer Frist |

Beide laufen über den Laravel-Scheduler. Es genügt **ein** Cron-Eintrag auf
dem Host, der den Scheduler jede Minute weckt:

```
* * * * * cd /pfad/zur/app && php artisan schedule:run >> /dev/null 2>&1
```

Alternativ (z. B. wenn kein Minuten-Cron erlaubt ist) lassen sich die Commands
direkt einplanen:

```
0 7 * * *  cd /pfad/zur/app && php artisan reports:remind >> storage/logs/cron.log 2>&1
30 3 * * * cd /pfad/zur/app && php artisan reports:prune  >> storage/logs/cron.log 2>&1
```

Hinweise:

- Bei `QUEUE_CONNECTION=sync` (Default) werden die Erinnerungs-Mails **inline**
  versendet – kein separater `queue:work`-Worker nötig. Bei `database`/`redis`
  zusätzlich einen Queue-Worker betreiben.
- `APP_URL` und `MAIL_*` müssen korrekt gesetzt sein (der Mail-Link zeigt aufs
  Dashboard).
- Trockenlauf zum Testen: `php artisan reports:remind --dry-run` bzw.
  `php artisan reports:prune --dry-run`. Übersicht: `php artisan schedule:list`.

## Entwicklung

```bash
# Im laufenden Container – der Host braucht kein PHP
docker compose exec app composer install
docker compose exec app vendor/bin/pint            # Autoformat
docker compose exec app vendor/bin/phpstan analyse # Statik, Level 9
docker compose exec app vendor/bin/phpunit         # 165 Tests
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
GET  /track                     Wiedereinstieg per Eingangscode (Formular)
POST /track                     Eingangscode einlösen (throttle)
GET  /file/{name}?id={uuid}     Datei-Download (throttle 60/min)
GET  /imprint, /privacy         statische Markdown-Seiten
GET  /setLang?lang=de|en        Sprach-Cookie setzen
GET  /dashboard                 Themenübergreifendes Admin-Dashboard
GET  /dashboard/export          CSV-Export der gefilterten Meldungen (admin)
GET  /newTopic/{id}             Topic anlegen/bearbeiten (admin)
GET  /reports/{id}              Reports zu einem Topic (admin)
POST /api/topic/{id}            Topic upsert (admin, JSON)
POST /api/topic/{t}/report/{r}/status       Status wechseln (admin)
POST /api/topic/{t}/report/{r}/acknowledge  Eingang bestätigen (admin)
POST /api/topic/{t}/reports/status          Bulk-Status (admin)
GET  /audit                     Audit-Log (nur globale Admins)
GET  /users                     Benutzerverwaltung (nur globale Admins)
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
- **PHPUnit**: 165 Tests (Unit + Feature), 480 Assertions

GitHub Actions führt die drei Stufen bei jedem Push/PR aus – Test-Matrix
gegen SQLite + MariaDB 11.

## Sicherheit

Eingebaute Härtung (Kurzfassung):

- CSRF: aktiv auf allen POST-Routen außer `/shib` + `/saml/*`.
- CSP, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` im
  `SecurityHeaders`-Middleware; HSTS bei HTTPS-Requests.
- Rate Limiting (`throttle:*,1`) auf `/submit`, `/report`, `/track`, `/file`,
  `/dev/login`, `/saml/out`, `/shib`.
- Markdown: escape → CommonMark → HTMLPurifier, restriktive
  Tag-Allowlist.
- Eingangscode wird nur als HMAC-SHA256 gespeichert; der Klartext verlässt die
  Bestätigungsseite genau einmal und wird nie persistiert.
- Webhooks nur über HTTPS, optional HMAC-SHA256-signiert
  (`MELDE_WEBHOOK_SECRET`); Benachrichtigungen enthalten keine Meldeinhalte.
- Append-only Audit-Log (Mutation/Löschung per Model-Guard unterbunden), ohne
  Melder*innen-PII.
- Dev-Login nur mit `APP_ENV != production` **und**
  `MELDE_DEV_LOGIN_ENABLED=true`.
- SAML-ACS-Endpoint validiert Signatur und NotBefore/NotOnOrAfter via
  `onelogin/php-saml`.

**Schwachstellen bitte vertraulich an `it-sicherheit@tum.de` melden,
nicht** über öffentliche GitHub-Issues. Details zum Reporting-Prozess,
Scope und Safe Harbor siehe [`SECURITY.md`](SECURITY.md).

## Danksagung

Besonderer Dank gilt **[TUM DEV](https://www.tum.dev/)** für die Vorarbeit,
die Code-Grundlage und das fortlaufende Engagement rund um quelloffene
Werkzeuge für die TUM-Community. TUM SafeSignal baut auf ihrer Arbeit auf.

## Lizenz

[MIT](LICENSE).
