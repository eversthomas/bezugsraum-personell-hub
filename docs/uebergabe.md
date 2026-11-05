# Übergabe: Stand der Entwicklung

Stand: 5. November 2026. Branch: `claude/personal-hub-entwicklung` (noch nicht in `master` gemergt).

## Erledigt

**AP 1 – Gerüst und Login** ist umgesetzt und getestet (39 PHPUnit-Tests grün gegen MariaDB 10.11, Code-Stil sauber, Login-Ablauf zusätzlich per echtem HTTP-Aufruf geprüft).

- Kern: Router, Container, Db (PDO), View, Session, CSRF, Crypto (libsodium), Migrator, Logger, Modulvertrag (`ModuleInterface`), Security-Header auf jeder Antwort.
- Anmeldung: Passwort, dann TOTP. Sperre nach 5 Fehlversuchen für 15 Minuten (je IP, zusätzlich 20 je Konto). TOTP-Code nur einmal verwendbar. „Gerät merken“ 30 Tage, nur als Hash gespeichert, einzeln widerrufbar (Repository vorhanden, Oberfläche fehlt noch). Session-ID und CSRF-Token werden nach Login erneuert.
- Ohne Login: nur `/login`, `/login/code` und statische Dateien; alles andere 404. `/` leitet zur Anmeldung.
- Oberfläche: Tokens-CSS mit Hell/Dunkel/Auto, Atkinson Hyperlegible Next (Tabellenziffern geprüft: `tnum` vorhanden, Inter als Ersatz nicht nötig), Login-Seiten, Platzhalter-Startseite mit Theme-Schalter und Abmelden.
- CI mit MariaDB-Dienst, Tests, PHP-CS-Fixer und PHPStan.

## Noch nicht geprüft

- **PHPStan (Level 6) lief lokal nicht.** In der Sandbox war das Paket nicht installierbar. Bitte lokal `composer stan` ausführen und die CI ansehen.
- Nicht auf All-Inkl getestet (AP 0 steht aus: PHP-Version, Erweiterungen, Subdomain, Datenbank).
- Passkey ist bewusst nicht enthalten (später laut Konzept).
- Fehlerseite für Anmeldungen zeigt kein Captcha o. Ä.; die Sperre ist die einzige Bremse.

## Abweichungen vom Konzept

- Tabellen `users`, `login_attempts` (mit zusätzlicher Spalte `user_hash`), `trusted_devices` sind in `001_init.sql`; die übrigen Tabellen kommen mit ihren Arbeitspaketen.
- Die Schrift besteht aus zwei WOFF2-Dateien (Latin und Latin Extended, per `unicode-range`) statt einer.
- `composer.lock` ist jetzt versioniert.
- Sitzungsdateien liegen in `storage/sessions/`.

## Nächster Schritt

**AP 2 – Kalenderquellen und Abruf** (siehe `docs/umsetzungskonzept.md`): `IcsFetcher` mit SSRF-Schutz, `IcsParser` mit sabre/vobject, verschlüsselte Quell-URLs, Seite „Kalenderquellen“ nach `docs/prototyp/Quellen.dc.html`, Cronjob.

## Lokal ausprobieren

```bash
cp config/config.example.php config/config.php
composer install
php bin/create-key.php && php bin/migrate.php
php bin/create-user.php <name>
php -S localhost:8080 -t public
```
