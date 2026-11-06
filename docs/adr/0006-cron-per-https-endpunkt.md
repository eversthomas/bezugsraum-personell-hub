# ADR 0006: Cron per HTTPS-Endpunkt

## Kontext

All-Inkl stößt Hintergrundarbeit über einen Cronjob an, der eine Adresse aufruft. Lokal soll derselbe Ablauf über die Kommandozeile laufen. Ein ungeschützter Endpunkt wäre von außen erreichbar.

## Entscheidung

Auf All-Inkl ruft der Cronjob minütlich per HTTPS `/cron/{token}` auf. Das Token umfasst mindestens 32 Byte und ist URL-sicher. Token und Passwort stehen nur als Hash in der Konfiguration.

Fehlt das Token oder stimmt es nicht, antwortet der Endpunkt mit 404 wie eine unbekannte Adresse. Erst ein richtiges Token prüft den HTTP-Basic-Zugang aus dem KAS. Fehlt er oder stimmt er nicht, antwortet der Endpunkt mit 401 und `WWW-Authenticate`. Viele Cron-Clients senden Benutzer und Passwort erst nach dieser Aufforderung.

Lokal bleibt `bin/cron.php`. Beide Wege starten dieselbe Logik. Die Aufträge kommen aus `ModuleInterface::cronJobs()` der aktiven Module.

## Folgen

Erinnerungen können näher am Zeitpunkt versendet werden als bei einem Viertelstundentakt. Ohne gültiges Token ist der Endpunkt von einer unbekannten Adresse nicht zu unterscheiden. `bin/cron.php` bleibt der Weg auf dem Entwicklungsrechner.
