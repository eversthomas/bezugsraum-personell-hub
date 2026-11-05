# Personal Hub

Persönliches, selbst gehostetes Organisationstool für eine Person.
Kern: nur lesende Kalender-Gesamtansicht über ICS, Inbox, Aufgaben, Notizen und Push-Erinnerungen.

## Status

AP 1 (Gerüst und Login) ist umgesetzt: Front-Controller, Router, Module, Migrationen, Anmeldung mit Passwort und TOTP,
Sperre nach Fehlversuchen, „Gerät merken“, CSRF-Schutz und Security-Header. Danach folgen die Kalenderquellen (AP 2).
Ohne Anmeldung liefert die Anwendung nur Login-Seite und Assets, alles andere antwortet mit 404.

## Anforderungen

- PHP 8.3 oder neuer
- Composer 2

- MariaDB (10.6 oder neuer) sowie die PHP-Erweiterungen `pdo_mysql`, `sodium`, `mbstring`; später `gd` oder `imagick`, `intl` und `curl`

## Lokaler Start

```bash
cp config/config.example.php config/config.php   # Datenbankzugang eintragen
composer install
php bin/create-key.php                           # Schlüssel für Verschlüsselung anlegen
php bin/migrate.php                              # Datenbank einrichten
php bin/create-user.php <benutzername>           # Nutzer mit Passwort und TOTP-Schlüssel anlegen
php -S localhost:8080 -t public
```

`bin/create-user.php` gibt den TOTP-Schlüssel und die `otpauth://`-URI aus. Beides in die Authenticator-App übernehmen.
Der Schlüssel in `config/keys/app.key` muss mitgesichert werden: Ohne ihn sind verschlüsselte Daten (TOTP-Geheimnisse, später ICS-Links) verloren.

Tests brauchen eine leere MariaDB-Datenbank (`personal_hub_test`, Zugang über `PH_TEST_DB_*`, Standard `ph`/`ph` auf 127.0.0.1). Ist sie nicht erreichbar, werden die Datenbanktests übersprungen.

`config/config.php` bleibt lokal und wird nicht versioniert. Abhängigkeiten werden für PHP 8.3 aufgelöst, auch wenn lokal eine neuere PHP-Version liegt.

## Dokumentation

- [Umsetzungskonzept](docs/umsetzungskonzept.md) ist die verbindliche Grundlage.
- [Architekturentscheidungen](docs/adr/)
- [Prototypen](docs/prototyp/) sind die Referenz für Layout, Farben und Abläufe. Ihr Code wird nicht übernommen.
