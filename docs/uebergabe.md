# Übergabe: Stand der Entwicklung

Stand: 5. November 2026. Branch: `claude/personal-hub-entwicklung` (noch nicht in `master` gemergt).

## Erledigt

**AP 1 – Gerüst und Login**

- Kern: Router, Container, Db (PDO), View, Session, CSRF, Crypto (libsodium), Migrator, Logger, Modulvertrag (`ModuleInterface`), Security-Header auf jeder Antwort.
- Anmeldung: Passwort, dann TOTP. Sperre nach 5 Fehlversuchen für 15 Minuten (je IP, zusätzlich 20 je Konto). TOTP-Code nur einmal verwendbar. „Gerät merken“ 30 Tage, nur als Hash gespeichert, einzeln widerrufbar (Repository vorhanden, Oberfläche fehlt noch). Session-ID und CSRF-Token werden nach Login erneuert.
- Ohne Login: nur `/login`, `/login/code` und statische Dateien; alles andere 404. `/` leitet zur Anmeldung.
- Tokens-CSS mit Hell/Dunkel/Auto, Atkinson Hyperlegible Next (Tabellenziffern geprüft: `tnum` vorhanden).
- CI mit MariaDB-Dienst, Tests, PHP-CS-Fixer und PHPStan.

**AP 2 – Kalenderquellen und Abruf**

- `IcsFetcher` mit SSRF-Schutz (`UrlGuard`, `IpPolicy`): nur https auf Port 443, keine Zugangsdaten in der URL, nur öffentliche IPs (v4, v6, eingebettetes v4), Verbindung an die geprüfte IP gebunden, höchstens 3 Weiterleitungen (jede neu geprüft), 10 s, 5 MB. Fehlermeldungen enthalten nie die URL.
- `IcsParser` (sabre/vobject): Windows- und IANA-Zeitzonen, Serien, Ausnahmen (EXDATE, RECURRENCE-ID), Ganztagstermine, abgesagte Termine. Fixtures für Outlook und Google unter `tests/fixtures/ics/` (selbst geschrieben, nicht aus echten Konten).
- `CalendarService`: anlegen, testen, ersetzen, pausieren, entfernen, Abruf mit ETag, Fehlerstatus pro Quelle, alte Termine bleiben bei Fehlern, Aufräumen nach 30 Tagen. URL nur verschlüsselt, in der Oberfläche nur der Servername.
- `bin/cron.php` (Sperrdatei, ein Lauf zur Zeit). Cronjob alle 15 Minuten einrichten.
- Seite „Kalenderquellen“ (`/einstellungen/quellen`) nach dem Prototyp, ohne Inline-Styles (CSP).

**AP 3 – Leitstand**

- Ansichten Tag, Woche, 2 und 4 Wochen. Zeitraster maßstabsgetreu, Ganztagszeile, überlappende Termine, Jetzt-Linie, große Uhr mit nächstem Termin und Countdown, Quellenfilter (wird gemerkt), Detailbereich, Aktualisierung alle 5 Minuten, Quellenfehler als Hinweis.
- Navigation: ab 900 px Seitenleiste (einklappbar, pro Gerät gemerkt), darunter Hamburger-Menü mit Off-Canvas (Escape, Tipp daneben, Wischen, Fokus bleibt im Menü).
- Per Playwright in Hell und Dunkel, Desktop und 390 px geprüft; keine Konsolenfehler.

## Noch nicht geprüft

- **PHPStan (Level 6) lief lokal nicht.** In der Sandbox war das Paket nicht installierbar. Bitte lokal `composer stan` ausführen und die CI ansehen.
- AP 2/3 sind nur mit selbst geschriebenen ICS-Dateien und Demo-Daten getestet, nicht mit echten M365-/Google-Links. Die Abnahme „alle vier Quellen aktuell“ steht aus.
- Der Leitstand hat keine automatisierten Browser-Tests; geprüft wurde per Playwright-Screenshot.
- Nicht auf All-Inkl getestet (AP 0 steht aus: PHP-Version, Erweiterungen, Subdomain, Datenbank).
- Passkey ist bewusst nicht enthalten (später laut Konzept).
- Fehlerseite für Anmeldungen zeigt kein Captcha o. Ä.; die Sperre ist die einzige Bremse.

## Abweichungen vom Konzept

- Tabellen `users`, `login_attempts` (mit zusätzlicher Spalte `user_hash`), `trusted_devices` sind in `001_init.sql`; die übrigen Tabellen kommen mit ihren Arbeitspaketen.
- Die Schrift besteht aus zwei WOFF2-Dateien (Latin und Latin Extended, per `unicode-range`) statt einer.
- `composer.lock` ist jetzt versioniert.
- Sitzungsdateien liegen in `storage/sessions/`.

## Nächster Schritt

**AP 4 – Erfassen und Inbox (PWA)** (siehe `docs/umsetzungskonzept.md`). Vorher sinnvoll: AP 0 (All-Inkl-Checks) und die Abnahme von AP 2/3 mit echten ICS-Links.

## Lokal ausprobieren

```bash
cp config/config.example.php config/config.php
composer install
php bin/create-key.php && php bin/migrate.php
php bin/create-user.php <name>
php -S localhost:8080 -t public
```
