# Übergabe: Stand der Entwicklung

Stand: 5. November 2026. Branch: `claude/personal-hub-entwicklung` (noch nicht in `master` gemergt).

## Erledigt

**AP 1 – Gerüst und Login**

- Kern: Router, Container, Db (PDO), View, Session, CSRF, Crypto (libsodium), Migrator, Logger, Modulvertrag (`ModuleInterface`), Security-Header auf jeder Antwort.
- Anmeldung: Passwort, dann TOTP. Sperre nach 5 Fehlversuchen für 15 Minuten (je IP, zusätzlich 20 je Konto). TOTP-Code nur einmal verwendbar. „Gerät merken“ 30 Tage, nur als Hash gespeichert, einzeln widerrufbar (Repository vorhanden, Oberfläche fehlt noch). Session-ID und CSRF-Token werden nach Login erneuert.
- Ohne Login: nur `/login`, `/login/code` und statische Dateien; alles andere 404. `/` leitet zur Anmeldung.
- Tokens-CSS mit Hell/Dunkel/Auto, Atkinson Hyperlegible Next (Tabellenziffern geprüft: `tnum` vorhanden).
- CI mit MariaDB-Dienst, Tests, PHP-CS-Fixer und PHPStan.

**AP 2 – Kalenderquellen und Abruf**

- `IcsFetcher` mit SSRF-Schutz (`UrlGuard`, `IpPolicy`): nur https auf Port 443, keine Zugangsdaten in der URL, nur öffentliche IPs (v4, v6, eingebettetes v4), Verbindung an die geprüfte IP gebunden, höchstens 3 Weiterleitungen (jede neu geprüft), 10 s, 5 MB. Fehlermeldungen enthalten nie die URL.
- `IcsParser` (sabre/vobject): Windows- und IANA-Zeitzonen, Serien, Ausnahmen (EXDATE, RECURRENCE-ID), Ganztagstermine, abgesagte Termine. Fixtures für Outlook und Google unter `tests/fixtures/ics/` (selbst geschrieben, nicht aus echten Konten).
- `CalendarService`: anlegen, testen, ersetzen, pausieren, entfernen, Abruf mit ETag, Fehlerstatus pro Quelle, alte Termine bleiben bei Fehlern, Aufräumen nach 30 Tagen. URL nur verschlüsselt, in der Oberfläche nur der Servername.
- `bin/cron.php` (Sperrdatei, ein Lauf zur Zeit). Cronjob alle 15 Minuten einrichten.
- Seite „Kalenderquellen“ (`/einstellungen/quellen`) nach dem Prototyp, ohne Inline-Styles (CSP).

**AP 3 – Leitstand**

- Ansichten Tag, Woche, 2 und 4 Wochen. Zeitraster maßstabsgetreu, Ganztagszeile, überlappende Termine, Jetzt-Linie, große Uhr mit nächstem Termin und Countdown, Quellenfilter (wird gemerkt), Detailbereich, Aktualisierung alle 5 Minuten, Quellenfehler als Hinweis.
- Navigation: ab 900 px Seitenleiste (einklappbar, pro Gerät gemerkt), darunter Hamburger-Menü mit Off-Canvas (Escape, Tipp daneben, Wischen, Fokus bleibt im Menü).
- Per Playwright in Hell und Dunkel, Desktop und 390 px geprüft; keine Konsolenfehler.

**AP 4 – Erfassen und Inbox (PWA)**

- Migration 003 (`items` nach ADR 0002). `ItemService::capture` ist idempotent über `client_uuid`.
- Seiten: `/erfassen` (auch Share-Target, GET mit title/text/url), `/inbox` (einsortieren als Aufgabe oder Notiz, löschen), Erfassungsfeld im Leitstand. API: `POST /api/v1/items`.
- Offline-Warteschlange liegt in `localStorage` (nicht im Service Worker), weil das CSRF-Token der Sitzung gebraucht wird. Gesendet wird beim nächsten Seitenaufruf, bei „online“ und jede Minute. Im Browser geprüft: Eintrag offline erfasst, nach Wiederverbindung genau einmal in der Datenbank; doppelter Versand ergibt 201 und dann 200.
- Service Worker: statische Dateien, Offline-Seite `/offline.html`; authentifizierte Seiten und API werden nie zwischengespeichert.
- „Sprechen“ ist Tastatur-Diktat (Fokus ins Textfeld, Herkunft `voice`). Foto kommt mit AP 6.

**AP 5 – Aufgaben und Notizen**

- Aufgaben: gruppiert (Überfällig, Heute, Demnächst, Später, Ohne Datum), nach Kontext filterbar, abhaken, wiederkehrend (täglich, wöchentlich mit Wochentag, monatlich mit Tag; ein Abhaken rückt auf den nächsten Termin). „Heute“ gilt in Europe/Berlin. Aufgaben-Karte im Leitstand (abhakbar).
- Notizen: Markdown (league/commonmark, HTML verworfen, `javascript:`-Links entfernt, externe Links mit `noopener noreferrer`), Kontext, Suche über Titel und Text (Wildcards werden wörtlich genommen).
- Schnellzugriff unter `/einstellungen/links` (Tabelle `quick_links`), Anzeige in Seitenleiste und mobilem Menü. Nur https, Symbol = Anfangsbuchstabe in Kontextfarbe.
- JSON-Export der Einträge unter `/export.json` (enthält keine Kalenderquellen und keine Links).
- **Nicht umgesetzt:** „In Outlook öffnen“ im Termindetail und die Verknüpfung eines Schnellzugriff-Links mit einer Kalenderquelle. Beides braucht Tests gegen den echten M365-Tenant (`login_hint`).

**AP 6 – Fotos**

- Foto-Taste in der Erfassung (`<input capture>`), Verkleinern im Browser (1600 px, JPEG), Vormerken in der Offline-Warteschlange (als Data-URL in `localStorage`; bei vollem Speicher gibt es eine Meldung).
- Server (`ImageProcessor`): Typ per Inhalt (JPEG, PNG, WebP, GIF), Pixelgrenze vor dem Dekodieren (40 MP), Ausrichtung wird angewendet, danach Neucodierung als JPEG (entfernt EXIF, Standort, angehängten Code), max. 1600 px, Transparenz wird weiß. Ablage in `storage/uploads/` mit Zufallsnamen, Rechte 0600. Auslieferung nur eingeloggt über `/media/{id}`.
- Im Browser geprüft: Foto mit eingebetteten GPS-Daten (2400×1800) wird 1600×1200 ohne GPS/EXIF gespeichert.
- Fotos hängen am Eintrag (Inbox, Notiz); beim Löschen werden die Dateien mitgelöscht.

**AP 7 – Push-Erinnerungen**

- Einrichten: `php bin/create-vapid.php`, Schlüssel in `config/config.php` (`vapid`) eintragen, dann unter „Benachrichtigungen“ je Gerät aktivieren und Testnachricht senden. Cronjob `php bin/cron.php` (alle 15 Minuten) verschickt die Erinnerungen.
- Aufgaben: Erinnerung am Fälligkeitstag um 8:00 Uhr (Berlin), genau einmal. Wiederkehrende Aufgaben erinnern beim nächsten Termin wieder. Erinnerungen, die mehr als 2 Tage zurückliegen (etwa nach einem Ausfall), werden still übersprungen. Scheitert der Versand an allen Geräten, wird die Erinnerung freigegeben und höchstens 3-mal versucht.
- Termine: Hinweis „5 bis 60 Minuten vorher“, je Kalenderquelle einstellbar (Seite Kalenderquellen). Jeder Termin wird genau einmal gemeldet, auch wenn der Abruf die Terminzeilen ersetzt. Bei einem Cron-Takt von 15 Minuten kommt der Hinweis zwischen 0 und 15 Minuten nach Erreichen der Vorlaufzeit; für genauere Hinweise den Cron häufiger laufen lassen (All-Inkl: Mindestintervall prüfen).
- Sicherheit: Push-Endpunkte werden vom Server aufgerufen, daher nur Endpunkte von Google, Mozilla, Apple und Microsoft (erweiterbar über `push.allowed_hosts`), nur https auf Port 443. Der private VAPID-Schlüssel wird nirgends ausgegeben.
- **`minishlink/web-push` braucht einen PSR-18-Client, und im Projekt ist keiner installiert.** Statt ein neues Paket aufzunehmen (laut Regeldatei nur nach Rückfrage), liegt unter `src/Notify/Http/` ein kleiner eigener PSR-7/17/18-Client auf cURL. Wenn du lieber Guzzle oder `symfony/http-client` nutzen willst, ist das ein Austausch in `MinishlinkPushSender` (Konstruktor-Argument `$client`).
- Die PHP-Erweiterung `gmp` oder `bcmath` beschleunigt die Verschlüsselung und unterdrückt eine Hinweismeldung der Bibliothek; bei All-Inkl in AP 0 prüfen.
- **Nicht geprüft:** der Zustellweg bis auf ein echtes Gerät. Getestet sind die Verschlüsselung und das Signieren (VAPID, aes128gcm) gegen einen Mitschnitt-Client sowie die gesamte Logik; Zustellung über FCM/Mozilla/Apple ist aus der Sandbox nicht möglich. Die Abnahme „Erinnerung kommt bei geschlossener App auf dem Pixel an“ steht aus.

**AP 8 – Zeitfenster reservieren (nach dem MVP, Kern umgesetzt)**

- `SlotFinder` (reine Rechenlogik, 13 Tests inkl. Zeitumstellung, Mitternacht, Puffer, Wochenenden): freie Fenster innerhalb der Arbeitszeiten über die Termine **aller aktiven Quellen**, dazu bereits vorgemerkte Blöcke. Ganztägige Termine sperren den Tag nicht.
- Einstellungen unter „Planung“: Arbeitstage, Beginn, Ende, Puffer, neutraler Standardtitel, Zielkalender je Kontext (Standard: Dienstlich, Betriebsrat, Ehrenamt → Outlook, Privat → Google).
- Dialog „Zeit reservieren“ an Aufgaben und Notizen (`/reservieren?item=ID`): Dauer wählen (30 bis 180 Min.), Vorschläge der nächsten 7 Tage, Titel und Zielkalender je Vorschlag anpassbar.
- Vorausgefüllte Links für Outlook im Web (`outlook.office.com/calendar/0/deeplink/compose`) und Google Kalender (Vorlage-Link) sowie `.ics`-Datei als Rückfallweg. Personal Hub schreibt in keinen Kalender; der Termin wird im geöffneten Formular vom Zielkonto gespeichert.
- Vorgemerkte Blöcke erscheinen gestrichelt im Leitstand (Tag- und Wochenansicht). Nach dem nächsten Abruf gilt ein Block als bestätigt, wenn ein Termin mit **derselben Start- und Endzeit** auftaucht (der Titel muss nicht passen, weil Outlook ihn je nach Detailstufe ausblendet). Nach einem Tag ohne Treffer verfällt er.
- **Offen (braucht dich):** Die Outlook- und Google-Links sind nur auf ihren Aufbau getestet, nicht gegen den AWO-Tenant oder das Handy. Vorab zu klären: öffnet der Link im richtigen M365-Konto (`login_hint` ist nicht eingebaut), und erscheint das ausgefüllte Formular auf dem Pixel? Der Host lässt sich über `new OutlookWebLinkBuilder('outlook.office365.com')` in `Calendar\Module` ändern.
- In der 2- und 4-Wochen-Ansicht werden vorgemerkte Blöcke noch nicht angezeigt.

## Noch nicht geprüft

- **PHPStan (Level 6) lief lokal nicht.** In der Sandbox war das Paket nicht installierbar. Bitte lokal `composer stan` ausführen und die CI ansehen.
- AP 2/3 sind nur mit selbst geschriebenen ICS-Dateien und Demo-Daten getestet, nicht mit echten M365-/Google-Links. Die Abnahme „alle vier Quellen aktuell“ steht aus.
- Der Leitstand hat keine automatisierten Browser-Tests; geprüft wurde per Playwright-Screenshot.
- Nicht auf All-Inkl getestet (AP 0 steht aus: PHP-Version, Erweiterungen, Subdomain, Datenbank).
- Installation als PWA, Share-Target und App-Shortcuts sind nur im Playwright-Chromium geprüft, nicht auf dem Pixel. Share-Target braucht eine installierte PWA.
- Die Kontext-Stammdaten (`contexts`) werden von `Calendar\SourceRepository` und `Core\ContextRepository` doppelt gelesen; bei Gelegenheit zusammenführen.
- Passkey ist bewusst nicht enthalten (später laut Konzept).
- Fehlerseite für Anmeldungen zeigt kein Captcha o. Ä.; die Sperre ist die einzige Bremse.

## Abweichungen vom Konzept

- Tabellen `users`, `login_attempts` (mit zusätzlicher Spalte `user_hash`), `trusted_devices` sind in `001_init.sql`; die übrigen Tabellen kommen mit ihren Arbeitspaketen.
- Die Schrift besteht aus zwei WOFF2-Dateien (Latin und Latin Extended, per `unicode-range`) statt einer.
- `composer.lock` ist jetzt versioniert.
- Sitzungsdateien liegen in `storage/sessions/`.

## Nächster Schritt

**AP 0 nachholen** (All-Inkl-Checks, siehe `docs/umsetzungskonzept.md`), dann Abnahme am Pixel, danach **AP 8 – Zeitfenster reservieren** (nach dem MVP). Vorher sinnvoll: AP 0 (All-Inkl-Checks) und die Abnahme von AP 2/3 mit echten ICS-Links.

## Lokal ausprobieren

```bash
cp config/config.example.php config/config.php
composer install
php bin/create-key.php && php bin/migrate.php
php bin/create-user.php <name>
php -S localhost:8080 -t public
```
