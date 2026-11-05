# ADR 0006: Cron per HTTPS-Endpunkt

## Kontext

All-Inkl stößt Hintergrundarbeit über einen Cronjob an, der eine Adresse aufruft. Lokal soll derselbe Ablauf über die Kommandozeile laufen. Ein ungeschützter Endpunkt wäre von außen erreichbar.

## Entscheidung

Auf All-Inkl ruft der Cronjob den Endpunkt minütlich per HTTPS auf. Nötig sind der HTTP-Benutzer und das HTTP-Passwort aus dem KAS sowie ein geheimes Token. Fehlt eines davon, antwortet der Endpunkt mit 404. Lokal bleibt `bin/cron.php`. Beide Wege starten dieselbe Logik. Den Pfad legt das Paket Härtung fest.

## Folgen

Erinnerungen können näher am Zeitpunkt versendet werden als bei einem Viertelstundentakt. Der Endpunkt verrät ohne die drei Merkmale nicht, dass er existiert. `bin/cron.php` bleibt der Weg auf dem Entwicklungsrechner.
