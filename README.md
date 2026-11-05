# Personal Hub

Persönliches, selbst gehostetes Organisationstool für eine Person.
Kern: nur lesende Kalender-Gesamtansicht über ICS, Inbox, Aufgaben, Notizen und Push-Erinnerungen.

## Status

Das MVP (AP 1 bis 7) und der Kern von AP 8 sind umgesetzt. Details und offene Punkte stehen in [docs/uebergabe.md](docs/uebergabe.md).

- **Leitstand:** nur lesende Gesamtansicht aller Kalender per ICS (Tag, Woche, 2 und 4 Wochen), Uhr mit nächstem Termin, Quellenfilter, Aufgaben-Karte
- **Kalenderquellen:** ICS-Links anlegen, testen, pausieren, ersetzen, entfernen; Links nur verschlüsselt gespeichert
- **Erfassen (PWA):** Text, Diktat, Foto; Offline-Warteschlange; Inbox zum Einsortieren
- **Aufgaben und Notizen:** Fälligkeit, Kontext, Wiederholung, Markdown, Suche, JSON-Export
- **Erinnerungen:** Web Push für fällige Aufgaben und optional vor Terminen
- **Zeit reservieren:** Vorschläge über alle Kalender, Link in den Zielkalender
- **Sicherheit:** Passwort plus TOTP, Sperre nach Fehlversuchen, gemerkte Geräte, CSRF, strenge Security-Header

Ohne Anmeldung liefert die Anwendung nur Login-Seite und Assets, alles andere antwortet mit 404.
Einrichtung auf einem Webspace: [docs/deployment.md](docs/deployment.md).

## Anforderungen

- PHP 8.3 oder neuer
- Composer 2

- MariaDB (10.6 oder neuer) sowie die PHP-Erweiterungen `pdo_mysql`, `sodium`, `mbstring`, `curl`, `gd`, `openssl`; empfohlen `gmp` oder `bcmath`

## Lokaler Start

```bash
cp config/config.example.php config/config.php   # Datenbankzugang eintragen
composer install
php bin/create-key.php                           # Schlüssel für Verschlüsselung anlegen
php bin/migrate.php                              # Datenbank einrichten
php bin/create-user.php <benutzername>           # Nutzer mit Passwort und TOTP-Schlüssel anlegen
php bin/create-vapid.php                         # Schlüssel für Push erzeugen (in config/config.php eintragen)
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
