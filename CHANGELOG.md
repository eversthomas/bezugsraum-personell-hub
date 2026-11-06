# Changelog

## [Unreleased]

### Added

- Agenda: die nächsten 14 Tage als Liste, gruppiert nach Tagen. „Heute“ und „Morgen“, danach Wochentag und Datum, mit Uhrzeit oder „ganztägig“ und dem Namen der Quelle.
- Kalenderseite unter `/kalender` mit der vollen Fläche. Die Startseite zeigt den Kalender in diesem Schritt noch mit.
- Kopfzeile auf allen angemeldeten Seiten: Datum, Uhrzeit und ISO-Kalenderwoche, minütlich und mit Tabellenziffern. Die große Uhr im Leitstand entfällt, der nächste Termin bleibt.
- Härtung: Cron-Endpunkt `/cron/{token}` mit Token und HTTP Basic, `bin/create-cron-credentials.php`, gemeinsame Auftragsliste mit `bin/cron.php`. Die CI prüft PHP 8.3 und 8.5 und führt `composer audit` aus.
- Aktualisieren ohne Terminal: angemeldete Nutzer sehen bei ausstehenden Migrationen nur „Datenbank aktualisieren“. Der Cron führt in der Zeit keine Aufträge aus. Cron-Hashes liegen in `storage/cron-credentials.php`, neu erzeugen unter „Sicherheit“.
- Installer im Browser: Einrichtungscode, Datenbank, Benutzer mit TOTP und Abschluss ohne Terminal. Liegt `config/config.php`, antworten die Installer-Pfade mit 404.
- Installer: falscher Dokumentenstamm stoppt mit Hinweis auf das Domainziel im KAS. Ohne Einrichtungscode keine Systemprüfung. `.htaccess` sperrt die Ordner neben `public/`, auch im Release-ZIP.
- Installer: ohne HTTPS nur noch für localhost, 127.0.0.1 und ::1. Nach dem Einrichtungscode wird die Sitzung erneuert. Der Verbindungstest wartet höchstens 3 Sekunden.
- Installer: das Benutzerpasswort liegt im Entwurf nur als Hash, der Entwurf verfällt nach 30 Minuten. Schlüssel, Konfiguration, Cron-Zugang und Einrichtungscode entstehen mit den Rechten 0600.
- Release: ein Workflow baut bei einem Tag `v*` das ZIP mit `vendor/` und hängt es an das GitHub-Release. Dokumentation außer `LICENSE` und `THIRD-PARTY-NOTICES.md` liegt nicht bei.
- Projektfundament: Verzeichnisstruktur, Werkzeugkonfiguration und Dokumentation.
- AP 1: Kern (Router, Container, Db, View, Session, CSRF, Crypto, Migrator), Module-Vertrag, Security-Header, Hell/Dunkel-Tokens mit Atkinson Hyperlegible Next.
- AP 1: Anmeldung mit Passwort und TOTP, Sperre nach 5 Fehlversuchen für 15 Minuten, „Gerät merken“ (30 Tage, nur als Hash), Logout.
- `bin/migrate.php`, `bin/create-user.php`, `bin/create-key.php`.
- AP 2: `IcsFetcher` mit SSRF-Schutz, `IcsParser` (Zeitzonen, Serien, Ausnahmen, Ganztag), Kalenderquellen mit verschlüsselten Links, Abruf-Cronjob (`bin/cron.php`) und Seite „Kalenderquellen“.
- AP 3: Leitstand mit Tag-, Wochen-, 2- und 4-Wochen-Ansicht, Uhr mit nächstem Termin und Countdown, Jetzt-Linie, Quellenfilter (wird gemerkt), Detailbereich, Aktualisierung alle 5 Minuten. JSON-Endpunkt `/api/v1/calendar/events`.
- AP 4: PWA (Manifest, Service Worker, Shortcuts, Share-Target), Erfassen mit Offline-Warteschlange (`client_uuid`, genau einmal), Inbox mit Einsortieren als Aufgabe oder Notiz, Erfassungsfeld im Leitstand, mobil nur Tagesansicht.
- AP 5: Aufgaben (Heute, Demnächst, Überfällig, nach Kontext, abhaken, wiederkehrend täglich/wöchentlich/monatlich), Notizen mit Markdown und Suche, Aufgaben-Karte im Leitstand, JSON-Export unter `/export.json`.
- AP 5: Schnellzugriff (`/einstellungen/links`) mit Anzeige in Seitenleiste und mobilem Menü; nur https, Symbol als Anfangsbuchstabe in Kontextfarbe, Öffnen in neuem Tab mit `noopener noreferrer`.
- AP 6: Fotos (Kamera per `<input capture>`, Verkleinern im Browser, serverseitig neu codiert als JPEG ohne EXIF und Standort, max. 1600 px, Auslieferung nur nach Login unter `/media/{id}`).
- AP 7: Push-Erinnerungen (Web Push mit VAPID): Geräte pro Gerät aktivieren, Testnachricht, Erinnerung für fällige Aufgaben (8:00 Uhr Berlin, genau einmal), optionaler Hinweis vor Terminen je Kalenderquelle, Cronjob. Eigener kleiner PSR-7/18-Client auf cURL, Push-Endpunkte nur von bekannten Diensten.
- Sicherheit: Seite mit gemerkten Geräten (einzeln oder alle entfernen) und Passwortänderung; `docs/deployment.md`.
- AP 8: Zeit reservieren (SlotFinder über alle Kalender, Arbeitszeiten und Puffer, Zielkalender je Kontext, Links für Outlook im Web und Google Kalender, `.ics`-Rückfallweg, gestrichelte Vormerkung im Leitstand, automatische Bestätigung nach dem Abruf).

### Fixed

- Die Tagesansicht zeigt den gewählten Tag. Blättern in den Wochenrastern setzt den Tag nicht mehr auf Montag.
- Termindetails bleiben im Blick: ab 1100 px ein Seitenpanel, darunter ein Blatt von unten. Der Fokus geht ins Panel und beim Schließen zurück auf den Termin.
- Termine von 30 Minuten zeigen Uhrzeit und Titel in einer Zeile. Der Titel wird gekürzt, die Schrift bleibt mindestens 13 px, die Höhe folgt der Uhr.
- Das Release-ZIP entsteht nur nach grüner Qualitätsprüfung und nur bei einem Tag `v*`. Daneben liegt eine SHA-256-Datei. Die Anleitung nennt `config.php`, das Cron-Token in Server-Logs und die Prüfung vor Ort.
- Der Release-Upload nennt das Repository, damit er ohne Checkout auskommt. Vor jedem Update gehört eine Datenbanksicherung im KAS dazu.
- Falsche Einrichtungscodes zählen je IP. Ab dem fünften Versuch verzögert die Antwort, der Code bleibt. Ein neuer Code entsteht erst nach 15 Minuten. Der Abschluss hält eine Dateisperre und lässt eine vorhandene Konfiguration stehen. HEAD auf `/cron/{token}` startet keinen Lauf.
- Passwortbestätigung und Passwortänderung zählen Fehlversuche wie die Anmeldung. Bei Sperre endet die Sitzung.
- Eine fehlgeschlagene Migration nennt nur die Datei und bleibt auf der Seite „Datenbank aktualisieren“. Gleichzeitige Läufe warten auf eine Sperre.
- Datenbanktests werden ohne erreichbare Datenbank übersprungen, ohne Fehler im Aufräumen.
- Bilder mit einer Kante über 1600 px werden verkleinert oder abgelehnt.
- `X-Forwarded-Proto` gilt nur, wenn `app.trust_proxy` gesetzt ist.
