# ADR 0007: MariaDB statt SQLite

## Kontext

SQLite war als Alternative vorgeschlagen: eine Datei, kein Datenbankserver, einfaches Backup. Code, Migrationen und CI sind bereits auf den MySQL-Dialekt ausgelegt.

## Entscheidung

Es bleibt bei MariaDB auf dem Server und MySQL lokal. Zugriff über PDO mit Prepared Statements. Jede Tabelle nutzt `utf8mb4` und `utf8mb4_unicode_ci`. MySQL-Sonderfunktionen werden nicht verwendet, damit der Dump auf MariaDB läuft. SQLite wird nicht eingeführt.

## Folgen

Migrationen und Tests bleiben beim bestehenden Dialekt. Ein späterer Wechsel der Datenbank wäre ein neues ADR. Die Volltextsuche nutzt die Mittel von MariaDB, nicht FTS5.
