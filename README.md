# Personal Hub

Persönliches, selbst gehostetes Organisationstool für eine Person.
Kern: nur lesende Kalender-Gesamtansicht über ICS, Inbox, Aufgaben, Notizen und Push-Erinnerungen.

## Status

Das Fundament steht: Verzeichnisstruktur, Konfiguration, Werkzeuge und Dokumentation.
Eine Anwendung gibt es noch nicht. `public/index.php` ist ein Platzhalter und antwortet mit HTTP 404.

## Anforderungen

- PHP 8.3 oder neuer
- Composer 2

MariaDB sowie die PHP-Erweiterungen `sodium`, `gd` oder `imagick`, `intl` und `curl` stehen in den Vorab-Checks des Umsetzungskonzepts. Dieser Platzhalter braucht sie noch nicht.

## Lokaler Start

```bash
cp config/config.example.php config/config.php
composer install
php -S localhost:8080 -t public
```

`http://localhost:8080/` antwortet mit Status 404 und dem Header `X-Robots-Tag: noindex, nofollow, noarchive`.

`config/config.php` bleibt lokal und wird nicht versioniert. Abhängigkeiten werden für PHP 8.3 aufgelöst, auch wenn lokal eine neuere PHP-Version liegt.

## Dokumentation

- [Umsetzungskonzept](docs/umsetzungskonzept.md) ist die verbindliche Grundlage.
- [Architekturentscheidungen](docs/adr/)
- [Prototypen](docs/prototyp/) sind die Referenz für Layout, Farben und Abläufe. Ihr Code wird nicht übernommen.
