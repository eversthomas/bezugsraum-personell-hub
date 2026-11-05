# Changelog

## [Unreleased]

### Added

- Projektfundament: Verzeichnisstruktur, Werkzeugkonfiguration und Dokumentation.
- AP 1: Kern (Router, Container, Db, View, Session, CSRF, Crypto, Migrator), Module-Vertrag, Security-Header, Hell/Dunkel-Tokens mit Atkinson Hyperlegible Next.
- AP 1: Anmeldung mit Passwort und TOTP, Sperre nach 5 Fehlversuchen für 15 Minuten, „Gerät merken“ (30 Tage, nur als Hash), Logout.
- `bin/migrate.php`, `bin/create-user.php`, `bin/create-key.php`.
- AP 2: `IcsFetcher` mit SSRF-Schutz, `IcsParser` (Zeitzonen, Serien, Ausnahmen, Ganztag), Kalenderquellen mit verschlüsselten Links, Abruf-Cronjob (`bin/cron.php`) und Seite „Kalenderquellen“.
