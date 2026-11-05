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
- `minishlink/web-push` **braucht einen PSR-18-Client, und im Projekt ist keiner installiert.** Statt ein neues Paket aufzunehmen (laut Regeldatei nur nach Rückfrage), liegt unter `src/Notify/Http/` ein kleiner eigener PSR-7/17/18-Client auf cURL. Wenn du lieber Guzzle oder `symfony/http-client` nutzen willst, ist das ein Austausch in `MinishlinkPushSender` (Konstruktor-Argument `$client`).
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

- **PHPStan (Level 6)** lief in der Sandbox nicht, wurde aber über die CI geprüft und ist grün (Stand letzter Commit). In `phpstan.neon` sind für `tests/` Array-Typangaben bewusst nicht verlangt, und für `IcsParser` ist eine sabre-bedingte Meldung ausgenommen.
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

## Weiterer Entwicklungsplan

Aufgenommen am 5. November 2026 aus dem lokalen Probebetrieb. Noch keine festgelegte Umsetzung.

1. **Installation auf einem Webhosting, ohne Terminal und ohne Composer dort.** Der Ablauf soll so schlicht werden wie bei WordPress oder ProcessWire: Dateien hochladen, im Browser einrichten. Composer bleibt das Werkzeug auf dem Entwicklungsrechner. Über GitHub entsteht ein installierbares Release, in dem `vendor/` schon enthalten ist. Auf dem Server wird nichts mit Composer nachinstalliert.
2. **Authenticator bleibt Pflicht.** Der erste Benutzer samt TOTP-Schlüssel entsteht heute nur über `php bin/create-user.php`. Auf einem Webhosting ohne Shell braucht es dafür einen Schritt im Browser, im selben Installationsgang wie Punkt 1. Der Code kommt weiterhin aus einer Authenticator-App, nicht aus einem abgeschwächten Login.

## Rückmeldungen aus dem Probebetrieb

Gesammelt am 5. November 2026. Noch keine Entscheidung und keine Umsetzung. Weitere Designvergleiche folgen.

### Kalender

- Termindetails erscheinen oben rechts. Liegt der Termin weiter unten, muss man nach oben scrollen, um sie zu lesen. Das soll ohne diesen Sprung gehen.
- In halbstündigen Terminen wird der Text abgeschnitten.
- Zusätzlich eine Agenda-Ansicht.
- Die Tagesansicht zeigt offenbar immer den Montag. Sie soll den heutigen Tag zeigen.
- Die Zwei-Wochen-Ansicht ist dieselbe Fläche wie vier Wochen, nur mit zwei statt vier Reihen. Der Platz soll in der Zwei-Wochen-Ansicht besser genutzt werden.
- Ein Jahresüberblick.
- Eine kurze Monatsübersicht zum Blättern durch die Monate. Eine weitere feste Spalte neben dem Hauptmenü wird vom Platz her eng. Idee: nur in der Kalenderansicht das eingeklappte Hauptmenü um diese Monatsübersicht ergänzen, und auch die wieder ausblenden können. Das trifft sich mit dem Wunsch, dem Kalender möglichst die ganze Fläche zu geben: die Monatsleiste darf den Kalender nicht dauerhaft verengen.
- Termine nicht nur lesen, sondern auch schreiben. Zunächst nur eine Idee. Das Ausmaß ist offen: ein eigener Termin in Personal Hub, Schreiben in den Ursprungskalender, oder etwas dazwischen. Heute ist der Kalender nur lesend. Anlegen läuft über vorausgefüllte Links in den Ursprungskonto (AP 8). Die Idee stellt das zur Diskussion, ohne es schon zu entscheiden.

### Kopfzeile

Uhrzeit und Datum als fester Kopf auf allen Seiten, nicht nur im Leitstand.

### Aufgaben, Notizen, Erfassen, Inbox

Die vier Punkte sind in der Benutzung schwer voneinander zu trennen. Offen, ob sie klarer abgegrenzt, knapper erklärt oder zu weniger Einstiegen zusammengelegt werden. Das Datenmodell mit einem gemeinsamen Eintragstyp bleibt davon zunächst unberührt. Diskussion, bevor etwas umgebaut wird.

### Leitstand und Kalender

Heute ist der Leitstand der Kalender. Zur Diskussion: Kalender als eigener Menüpunkt mit möglichst viel Fläche für viele Quellen. Der Leitstand wird ein Überblick (Dashboard). „Was geht dir durch den Kopf“ nur dort, nicht auf der Kalenderseite.

### Gestaltung

Das jetzige Design und das einklappbare Hauptmenü passen, auch die mobile Ansicht. Gewünscht ist eine insgesamt filigranere Gestaltung. Konkrete Vorbilder kommen noch.



## Idee: Kalender-Ergänzungen „Freie Zeit“ und „Horizont“

Grundsatz: Beides sind optionale Ebenen, keine neuen Pflichtansichten. Die

bestehenden Ansichten (Tag, Woche, 2 Wochen, 4 Wochen) bleiben unverändert

der Standard. Jede Ergänzung lässt sich einzeln ein- und ausschalten:

- als Schalter direkt neben der Zeitraumwahl

- Zustand pro Gerät gemerkt (wie Kalenderfilter und Darstellung)

- Standard: aus

Alles bleibt nur lesend und arbeitet nur mit den vorhandenen Termindaten

(Titel, Zeit, Ort, Quelle).

### 1. Freie Zeit und Übergänge

Zweck: Lücken und enge Übergänge auf einen Blick erkennen.

a) Freie Zeit (Schalter „Freie Zeit“)

- Lücken zwischen Terminen aller eingeblendeten Quellen werden als eigene,

  zurückhaltende Fläche dargestellt: gestrichelte Haarlinie, Text

  „frei · 1 Std 30“, keine Füllfarbe.

- Nur innerhalb der Arbeitszeit aus den Einstellungen (Standard 08:00–17:00),

  am Wochenende nur, wenn die Arbeitszeit das vorsieht.

- Mindestlänge einstellbar (Standard 30 Min); kürzere Lücken werden nicht

  beschriftet.

- Ausgeblendete Quellen zählen nicht als belegt.

- Ganztägige Termine blockieren keine Zeit.

- Antippen einer Lücke öffnet „Zeit reservieren“ (AP 8) mit

  vorausgewähltem Zeitfenster.

- Ansichten: Tag und Woche als Fläche im Zeitraster; in 2 und 4 Wochen

  nur als Summe pro Tag („3 Std frei“) in der Tageszelle.

b) Übergänge (Schalter „Übergänge“)

- Stößt ein Termin ohne Pause an den nächsten, erscheint an der Kante eine

  kleine Markierung „kein Puffer“.

- Folgen drei oder mehr Termine ohne Pause aufeinander, zusätzlich ein

  Hinweis am Tag („3 Termine am Stück“).

- Puffergrenze einstellbar (Standard 10 Min).

- Nur Tag und Woche.

Logik: ein Service `Calendar\GapFinder`, abgestimmt mit dem vorhandenen

`SlotFinder` aus AP 8 (gemeinsame Logik wiederverwenden, nicht doppeln).

Eingabe: Termine eines Zeitraums, Arbeitszeit, Mindestlänge; Ausgabe:

Lücken und Übergänge. Mit Tests für Überlappungen, ganztägige Termine,

Termine über Mitternacht und ausgeblendete Quellen.

### 2. Horizont

Zweck: eine fortlaufende Ansicht, die nah liegende Zeit ausführlich und

ferne Zeit verdichtet zeigt – statt zwischen Reitern zu wechseln.

- Wählbar als zusätzlicher Eintrag in der Zeitraumwahl („Horizont“),

  gleichrangig zu Tag, Woche usw. Kein Standard.

- Möglicher fester Platz: im künftigen Leitstand als Überblick, wenn

  Kalender und Leitstand getrennt werden.

- Aufbau von oben nach unten (Desktop und mobil gleich, vertikal scrollend):

  1. Heute: alle Termine mit Uhrzeit, Ort und Quelle, Jetzt-Markierung,

     vergangene Termine blasser

  2. Morgen: Termine als kompakte Zeilen (Uhrzeit, Titel, Quellpunkt)

  3. Rest der Woche: pro Tag eine Zeile mit Lastbalken (gebuchte Stunden,

     nach Quellfarben unterteilt) und den ersten zwei Titeln, Rest als „+3“

  4. Nächste drei Wochen: pro Woche eine Zeile mit Lastbalken je Tag und

     ganztägigen Einträgen (z. B. Urlaub) als Text

- Jede verdichtete Zeile lässt sich aufklappen und zeigt dann die Termine

  wie unter „Morgen“.

- Lastbalken: Höhe oder Länge proportional zu gebuchten Stunden innerhalb

  der Arbeitszeit, mit Textwert für Screenreader und beim Überfahren

  („5 Std gebucht, 3 Std frei“). Farbe nie als einzige Information.

- Mit „Freie Zeit“ kombinierbar: dann zeigen die Zeilen zusätzlich die

  freien Stunden.

Nicht Teil dieser Idee: Bewertungen der Auslastung („zu viel“), Statistiken

über Rollen, Bearbeiten von Terminen.

## Idee: Filigranere Gestaltung

Referenz: docs/prototyp/Feinschliff.dc.html (Gestaltung, nicht Code).

- Haarlinien statt Kästen: Trennlinien 1 px mit geringem Kontrast statt

  Karten mit Rahmen und Schatten.

- Hierarchie über Typografie: kleine Labels in Versalien mit Sperrung,

  große Werte in leichtem Schnitt (Uhr 200–300), Fließtext 400,

  Fett sparsam.

- Weniger Farbe: Quellfarben nur als feine Kante und zarte Tönung, eine

  Akzentfarbe für Interaktion, keine schwarzen Primärknöpfe.

- Kleinere Radien (4–6 px), striktes 4-px-Raster, mehr Weißraum.

- Zurückhaltende Bedienelemente: Zeitraumwahl als Textreiter mit feiner

  Unterstreichung, Erfassen als Linie statt Textbox, dünne Icons statt

  Buchstaben-Badges in der Navigation.

- Mindestgrößen bleiben verbindlich: Text nie unter 13 px, Touch-Ziele

  44 px. Die Referenz unterschreitet das bei Labels (11–12 px); die

  feinere Wirkung entsteht über Gewicht, Sperrung, Farbe und Abstand,

  nicht über kleinere Schrift.

- Umsetzung zentral über Tokens und Komponenten; vorher die doppelten

  dunklen Tokens zusammenführen.

## Lokal ausprobieren

```bash
cp config/config.example.php config/config.php
composer install
php bin/create-key.php && php bin/migrate.php
php bin/create-user.php <name>
php -S localhost:8080 -t public
```
