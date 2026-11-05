# ADR 0009: Installer im Browser mit Einrichtungscode

## Kontext

Auf dem Webhosting gibt es kein Terminal und kein Composer. Der erste Benutzer samt TOTP entsteht heute nur über Skripte in `bin/`. Eine offene Installationsseite würde den ersten Aufrufer zum Administrator machen.

## Entscheidung

Vor dem ersten Deployment richtet der Browser die Anwendung ein. Der Installer ist nur aktiv, solange `config/config.php` fehlt. Der erste Aufruf schreibt einen Zufallscode nach `storage/install-code.txt` außerhalb des Webroots. Ohne diesen Code im Formular gibt es keine Installation. Das Formular prüft den Datenbankzugang, schreibt die Konfiguration, legt den Schlüssel an, spielt die Migrationen ein, erzeugt die VAPID-Schlüssel und den ersten Benutzer mit Passwort und TOTP. Der QR-Code kommt aus einem kleinen etablierten Paket, das im Installer-Paket nach Rückfrage gewählt wird. Das Geheimnis steht zusätzlich als Text auf der Seite. Angezeigt wird die Cron-Adresse samt Token. Danach entstehen eine Sperrdatei und das Löschen des Codes. Die Route antwortet dauerhaft mit 404, auch nach Löschen der Sperrdatei, solange `config/config.php` existiert. Das Release-ZIP enthält `vendor/` und wird per GitHub-Workflow gebaut. Eine Person pro Installation.

## Folgen

`bin/` bleibt der Weg für die lokale Entwicklung. Auf dem Server läuft kein Composer. Der Einrichtungscode wird im Dateimanager des KAS oder per FTP gelesen.
