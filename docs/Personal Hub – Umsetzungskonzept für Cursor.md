# Personal Hub – Umsetzungskonzept für Cursor

Nov 3, 2026 · @Thomas Evers

Personal Hub wird als schlankes, eigenes PHP-Projekt mit Cursor gebaut, in sieben Arbeitspaketen bis zum MVP und einem achten danach, jeweils mit klaren Stopp-Punkten. Grundlage sind die drei Prototypen auf der Design-Fläche (Leitstand, Kalenderquellen, Mobil). Das große Konzeptpapier dient nur noch als Hintergrund.

## Ziel und Umfang

**Das MVP enthält:**

- **Leitstand (Desktop):** nur lesende Gesamtansicht aller Kalender per ICS, Ansichten Tag, Woche, 2 Wochen, 4 Wochen, Jetzt-Linie, nächster Termin, Quellen ein- und ausblendbar
- **Kalenderquellen:** ICS-Links anlegen, testen, pausieren, ersetzen, entfernen
- **Erfassen (Mobil-PWA):** Schreiben, Sprechen (Tastatur-Diktat), Foto; alles landet in der Inbox
- **Inbox:** Einträge als Aufgabe oder Notiz einsortieren oder löschen
- **Aufgaben:** Fälligkeit, Kontext, erledigt; wiederkehrende Aufgaben
- **Notizen:** Text mit Markdown, Kontext, optional Foto
- **Erinnerungen:** Web Push für fällige Aufgaben und optional vor Terminen
- **Sicherheit:** Login mit Passkey oder Passwort plus TOTP, verschlüsselte ICS-Links

**Bewusst nicht im MVP:** E-Mail, KI, Projekte mit Meilensteinen, Mehrbenutzer, Schreibzugriff auf fremde Kalender, Offline-Bearbeitung (außer der Erfassungs-Warteschlange), Blättern in die Vergangenheit über 30 Tage hinaus.

## Technische Leitplanken

Die Entscheidung fällt für ein eigenes PHP-Projekt ohne Framework und ohne CMS. Die Oberfläche ist der Kern, und dafür braucht es vor allem schlanke JSON-Endpunkte. Den Rest liefern wenige, bewährte Pakete.

| Bereich | Festlegung |
| --- | --- |
| PHP | 8.3 (vorab bei All-Inkl prüfen, Minimum 8.2) |
| Datenbank | MariaDB, Zugriff über PDO mit Prepared Statements |
| Frontend | Server-HTML + Vanilla JS, eigenes CSS mit Tokens, kein Build-Schritt |
| Kalender-UI | eigene Ansichten nach Prototyp (kein FullCalendar), Daten per JSON |
| ICS | `sabre/vobject` (Parsen, Wiederholungen auflösen) |
| Markdown | `league/commonmark` mit deaktiviertem HTML |
| Push | `minishlink/web-push` |
| 2FA / Passkey | `spomky-labs/otphp` (TOTP), später `web-auth/webauthn-lib` |
| Bilder | GD oder Imagick serverseitig, Verkleinern zusätzlich im Browser |
| Hintergrund | Cronjob alle 15 Minuten ruft `bin/cron.php` auf |
| Deployment | `vendor/` wird mit hochgeladen, kein Composer auf dem Server nötig |
| Lizenz | MIT, Abhängigkeiten in `THIRD-PARTY-NOTICES.md` |

Warum keine Kalender-Bibliothek: Die Ansichten aus dem Prototyp sind einfacher als FullCalendar und passen genau zum Bedarf. Nur lesend, ohne Drag-and-drop, sind sie mit wenig Code gut beherrschbar.

### Schrift

Die gesamte Oberfläche nutzt eine einzige Schrift: **Atkinson Hyperlegible Next** (Braille Institute, Open Font License). Sie wurde für Lesbarkeit entwickelt: Verwechslungsgefahr wie bei I, l und 1 oder O und 0 ist gezielt ausgeschlossen, die Formen sind offen und gut unterscheidbar, auch klein und bei müden Augen.

| Punkt | Festlegung |
| --- | --- |
| Datei | eine variable WOFF2-Datei (Gewichtsachse), nur aufrecht, Teilmenge Latin + Latin Extended |
| Ablage | `public/assets/fonts/`, Einbindung per `@font-face` mit `font-display: swap` und `preload` |
| Gewichte | 400 Text, 500 Hervorhebung, 600 Überschriften und Buttons, 300 nur für die große Uhr |
| Größen | Grundtext 16 px, nie unter 13 px, Zeilenhöhe 1,5 |
| Zahlen | Uhrzeiten mit `font-variant-numeric: tabular-nums`, falls die Schrift das unterstützt; sonst Zeitspalten mit fester Breite |
| Kursiv | keine Kursivschrift im UI (`font-synthesis: none`) |
| Sicherheit | CSP `font-src 'self'`, keine Anfrage an Google |
| Lizenz | OFL-Text in `THIRD-PARTY-NOTICES.md` |

In AP 1 prüfen: Unterstützt die Datei Tabellenziffern? Falls nicht und die Uhr springt sichtbar, ist **Inter** der erprobte Ersatz mit denselben Regeln.

## Projektstruktur

Nur `public/` liegt im Webroot. Code, Konfiguration, Schlüssel und Uploads sind von außen nicht erreichbar.

```
personal-hub/
├─ public/                 ← einziges Webroot (Domain zeigt hierhin)
│  ├─ index.php            Front-Controller, alle Anfragen laufen hier durch
│  ├─ manifest.webmanifest PWA, inkl. Shortcuts und Share-Target
│  ├─ sw.js                Service Worker (Push, Erfassungs-Warteschlange)
│  └─ assets/
│     ├─ css/              tokens.css, base.css, components.css
│     └─ js/               ein Modul pro Ansicht, kein Bundler
├─ src/
│  ├─ Core/                Router, Request, Response, Db, View, Csrf, Crypto, Container, Contracts/
│  ├─ Auth/                Login, Session, TOTP, später Passkey
│  ├─ Calendar/            Quellen, IcsFetcher, IcsParser, EventCache
│  ├─ Items/               Inbox, Aufgaben, Notizen (gemeinsamer Eintragstyp)
│  ├─ Media/               Foto-Upload, EXIF entfernen, Auslieferung
│  └─ Notify/              Erinnerungen, Push-Abos, Versand
├─ templates/              PHP-Templates je Ansicht
├─ migrations/             001_init.sql, 002_… (nummeriert, nur vorwärts)
├─ bin/                    cron.php, migrate.php, create-user.php
├─ storage/                uploads/, logs/, cache/ (nicht im Webroot)
├─ config/                 config.php (aus config.example.php), keys/
├─ tests/                  PHPUnit, Fixtures mit Beispiel-ICS-Dateien
├─ docs/                   Entscheidungen (ADR), Deployment, prototyp/
├─ .cursor/rules/          Regeln für Cursor (siehe unten)
├─ composer.json
└─ README.md
```

Die Prototyp-Dateien kommen als Referenz nach `docs/prototyp/`. Cursor soll Aufbau und Gestaltung daran ausrichten, aber den Code nicht übernehmen.

## Code-Architektur

Personal Hub ist ein **modularer Monolith**: eine Anwendung, klar getrennte Module, ein kleiner eigener Kern. Erweiterbar über wenige Schnittstellen, aber bewusst ohne Plugin-System für Dritte.

### Schichten in jedem Modul

| Schicht | Aufgabe | Darf nicht |
| --- | --- | --- |
| Controller | Anfrage prüfen, Service aufrufen, Antwort (HTML oder JSON) | SQL enthalten, Geschäftslogik |
| Service | Geschäftslogik, Transaktionen | HTML erzeugen, auf `$_POST` zugreifen |
| Repository | SQL, nur für die eigenen Tabellen | Logik, fremde Tabellen |
| Entity / Value Object | Daten mit Regeln, z. B. `Item`, `CalendarEvent`, `TimeRange` | Datenbankzugriff |

Abhängigkeiten zeigen nur nach unten. Module sprechen miteinander ausschließlich über Service-Interfaces, nie über fremde Repositories.

### Modulvertrag

Jedes Modul hat eine `Module.php`, die sich beim Kern anmeldet. Welche Module aktiv sind, steht in `config/modules.php`.

```php
interface ModuleInterface
{
    public function routes(Router $router): void;
    public function services(Container $container): void;
    /** @return CronJob[] */
    public function cronJobs(): array;
    /** @return NavItem[] */
    public function navItems(): array;
}
```

Der Container ist selbst geschrieben (rund 50 Zeilen): Fabriken pro Service, keine Autowiring-Magie. So bleibt nachvollziehbar, was woher kommt.

### Erweiterungspunkte

| Schnittstelle | Heute | Später möglich |
| --- | --- | --- |
| `CalendarProviderInterface` | ICS-Feed | CalDAV, eigene lokale Termine |
| `NotificationChannelInterface` | Web Push | E-Mail |
| `EventLinkBuilderInterface` | Outlook im Web, Google Calendar (AP 8) | weitere Kalenderdienste |
| `MediaStorageInterface` | lokaler Ordner `storage/uploads/` | anderer Speicherort |

### Interne API

Alle dynamischen Teile laufen über JSON unter `/api/v1/…` mit derselben Anmeldung und CSRF-Prüfung wie die Oberfläche. Die Versionierung erlaubt später eine KI-Anbindung (etwa als MCP-Server), ohne die Oberfläche umzubauen.

### Frontend-Struktur

- CSS in vier Dateien: `tokens.css` (Farben hell und dunkel, Abstände, Schrift), `base.css`, `components.css`, `views.css`
- JS als ES-Module ohne Bundler: `api.js` (fetch, CSRF, Fehler), `ui/` (Toast, Bottom-Sheet, Segment-Schalter), ein Modul pro Ansicht
- Kein Framework; Komponenten sind kleine Funktionen, die vorhandenes Server-HTML anreichern

### Qualität (nur in der Entwicklung)

- PHPUnit für Services, PHPStan Level 6, PHP-CS-Fixer nach PSR-12
- GitHub Actions führt Tests und PHPStan bei jedem Push aus
- Deployment mit `composer install --no-dev`; Dev-Werkzeuge landen nie auf dem Server
- SemVer, `CHANGELOG.md`, Architekturentscheidungen als kurze ADRs in `docs/adr/`

### Nicht erkennbar für Suchmaschinen

- `X-Robots-Tag: noindex, nofollow, noarchive` auf **jeder** Antwort, auch für Bilder, Manifest und API
- `robots.txt` mit `Disallow: /`, keine Sitemap, keine Social-Media-Vorschau (OpenGraph)
- Ohne Login liefert die Anwendung nur Login-Seite, Manifest, Service Worker und Assets; alles andere antwortet mit 404 statt 403, damit nichts über vorhandene Pfade verraten wird
- Login-Seite mit neutralem Titel, ohne Namen
- `Referrer-Policy: no-referrer`, damit Links aus Notizen keine Adresse der Anwendung weitergeben
- Subdomain nirgends verlinkt und nicht erratbar

### Bewusst nicht

Kein ORM, kein Event-Bus, kein SPA-Framework, kein Plugin-Marktplatz. Wenn eine dieser Strukturen später wirklich gebraucht wird, entsteht sie aus konkretem Bedarf und wird als ADR begründet.

## Datenmodell

Aufgaben, Notizen und Inbox teilen sich eine Tabelle `items`. Ein Eintrag beginnt als `inbox` und wird durch Einsortieren zu `task` oder `note`. Das spart Formulare und Sonderfälle und passt zu „Erfassen vor Sortieren“.

| Tabelle | Zweck | Wichtige Felder |
| --- | --- | --- |
| `items` | Inbox, Aufgaben, Notizen | `id`, `type` (inbox/task/note), `origin` (text/voice/photo/link/share), `title`, `body` (Markdown), `context`, `due_date`, `recurrence` (z. B. `weekly:mon`), `done_at`, `archived_at`, `created_at`, `updated_at`, `client_uuid` |
| `item_media` | Fotos zu Einträgen | `item_id`, `file_name`, `mime`, `width`, `height`, `bytes` |
| `contexts` | Dienstlich, Betriebsrat, Ehrenamt, Privat, Projekte | `key`, `label`, `color`, `sort` |
| `calendar_sources` | ICS-Quellen | `name`, `context`, `color`, `url_encrypted`, `url_hint`, `interval_min`, `active`, `last_ok_at`, `last_error`, `etag` |
| `calendar_events` | zwischengespeicherte Termine | `source_id`, `uid`, `starts_at` (UTC), `ends_at`, `all_day`, `title`, `location`, `fetched_at` |
| `reminders` | geplante Erinnerungen | `item_id`, `remind_at`, `sent_at`, `attempts` |
| `push_subscriptions` | Geräte für Push | `endpoint`, `p256dh`, `auth`, `device_label`, `created_at` |
| `trusted_devices` | „Dieses Gerät merken“ | `token_hash`, `device_label`, `expires_at`, `last_seen_at` |
| `login_attempts` | Schutz vor Durchprobieren | `ip_hash`, `attempted_at`, `success` |

Regeln für das Modell:

- Alle Zeiten in UTC speichern, in Europe/Berlin anzeigen.
- `client_uuid` macht das Erfassen idempotent: Kommt ein Offline-Eintrag doppelt an, wird er nur einmal gespeichert.
- Wiederkehrende Termine werden beim Abruf für den Zeitraum −30 bis +90 Tage aufgelöst und als Einzeltermine gespeichert. Die Ansicht muss dann nichts mehr rechnen.
- Beim Abruf einer Quelle werden ihre Termine im Zeitfenster vollständig ersetzt (in einer Transaktion). Schlägt der Abruf fehl, bleibt der alte Stand stehen.
- Termine älter als 30 Tage löscht der Cronjob.
- Kontext ist Einordnung, keine Zugriffsgrenze.

## Sicherheit

Sicherheit gehört in Arbeitspaket 1, nicht ans Ende. Diese Punkte sind Pflicht und werden bei jedem Review geprüft:

- [ ] Login mit Passwort (`password_hash`) plus TOTP; Passkey als spätere Komfortstufe
- [ ] Sperre nach 5 Fehlversuchen für 15 Minuten
- [ ] Session-ID nach Login erneuern; Cookies `Secure`, `HttpOnly`, `SameSite=Lax`
- [ ] „Gerät merken“ für 30 Tage, nur als Hash gespeichert, einzeln widerrufbar
- [ ] CSRF-Token für jede ändernde Anfrage, auch für JSON
- [ ] Ausgabe immer escapen; Markdown ohne HTML rendern
- [ ] ICS-Links mit libsodium verschlüsseln, Schlüssel in `config/keys/`; in der Oberfläche nur `url_hint`
- [ ] ICS-Abruf: nur `https`, keine privaten oder lokalen IP-Adressen, höchstens 3 Weiterleitungen (jede neu geprüft), 10 s Zeitlimit, 5 MB Größenlimit
- [ ] Fotos: Typ per Inhalt prüfen, neu codieren (entfernt EXIF und Standort), max. 1600 px, Ablage in `storage/uploads/`, Auslieferung nur nach Login
- [ ] Logs ohne Termintitel, Notizinhalte, URLs oder Tokens
- [ ] Security-Header: CSP ohne externe Quellen, HSTS, `X-Content-Type-Options`, `Referrer-Policy`
- [ ] `robots.txt` mit Disallow, `noindex`-Header
- [ ] Verschlüsseltes Backup von Datenbank, Uploads und Schlüssel; Wiederherstellung einmal testen

## Regeldatei für Cursor

Diese Datei als `.cursor/rules/personal-hub.mdc` anlegen. Cursor lädt sie bei jeder Anfrage. Sie ersetzt lange Prompts und hält die KI in den Leitplanken.

```markdown
---
description: Grundregeln für Personal Hub
alwaysApply: true
---

# Personal Hub – Regeln

## Projekt
Persönliches, selbst gehostetes Organisationstool für eine Person.
Kern: nur lesende Kalender-Gesamtansicht (ICS), Inbox, Aufgaben, Notizen, Push-Erinnerungen.
Gestaltung und Abläufe: siehe docs/prototyp/. Code daraus nicht übernehmen.

## Technik
- PHP 8.3, strict_types, PSR-4 unter src/, kein Framework, kein ORM.
- Datenbank nur über Core\Db (PDO, Prepared Statements). Nie SQL mit Strings zusammenbauen.
- Frontend: Server-HTML aus templates/ + Vanilla-JS-Module. Kein Build-Schritt, kein npm, keine CDNs.
- CSS nur über Tokens aus public/assets/css/tokens.css.
- Neue Composer-Pakete nur nach Rückfrage.

## Architektur
- Module (Auth, Calendar, Items, Media, Notify) greifen nicht direkt auf fremde Tabellen zu,
  sondern über den Service des anderen Moduls.
- Controller dünn, Logik in Services, Services ohne HTML, SQL nur in Repositories.
- Jedes Modul meldet sich über Module.php an (ModuleInterface); keine globalen Funktionen außer e().
- Externe Dienste nur über die Interfaces in src/Core/Contracts.
- Jede Antwort trägt X-Robots-Tag: noindex, nofollow, noarchive.
- Schemaänderungen nur als neue Datei in migrations/, nie bestehende ändern.
- Zeiten in UTC speichern, in Europe/Berlin anzeigen.

## Sicherheit (nicht verhandelbar)
- Jede ändernde Anfrage prüft Login und CSRF.
- Jede Ausgabe wird escaped (Helper e()).
- ICS-URLs nur verschlüsselt speichern (Core\Crypto), nie ausgeben oder loggen.
- Externe Abrufe nur über Calendar\IcsFetcher (SSRF-Schutz).
- Keine Inhalte (Titel, Notizen, URLs) in Logs.

## Arbeitsweise
- Nur das umsetzen, was im aktuellen Arbeitspaket steht. Nichts „nebenbei“ verbessern.
- Vor größeren Änderungen kurz den Plan nennen und auf Freigabe warten.
- Zu jeder Logik in Services einen PHPUnit-Test schreiben.
- Am Ende jedes Schritts: welche Dateien geändert wurden und wie man es testet.
- Sprache in Oberfläche und Kommentaren: Deutsch. Bezeichner im Code: Englisch.
```

## Arbeitspakete

Der Kalender kommt vor den Aufgaben, weil er der eigentliche Schmerzpunkt ist. Nach Paket 3 ist der Leitstand im Alltag nutzbar. Jedes Paket endet mit einem Stopp: testen, committen, erst dann weiter.

### AP 0 – Vorab-Checks (ohne Code)

- [ ] ICS-Veröffentlichung in allen drei M365-Konten testen, Detailstufe „Titel und Orte“
- [ ] Google: geheime iCal-Adresse kopieren
- [ ] All-Inkl: PHP 8.3, Erweiterungen `sodium`, `gd` oder `imagick`, `intl`, `curl`; Cron-Intervall; Upload-Limit
- [ ] Subdomain mit HTTPS anlegen, Domainziel auf `public/`
- [ ] Datenbank anlegen

### AP 1 – Gerüst und Login

Ziel: leere, aber sichere Anwendung, die man auf All-Inkl aufrufen kann.

- [ ] Front-Controller, Router, Templates, Tokens-CSS mit Hell- und Dunkelmodus (Auto folgt dem System, dazu Hell und Dunkel manuell, pro Gerät gemerkt; Quellfarben mit eigener dunkler Variante)
- [ ] Migrationen + `bin/migrate.php`, `bin/create-user.php`
- [ ] Login mit Passwort + TOTP, Sperre, „Gerät merken“, Logout
- [ ] CSRF, Security-Header, Fehlerseite ohne Details
- [ ] Abnahme: Login am Desktop und am Pixel klappt; ohne Login ist keine Seite und keine Datei erreichbar

### AP 2 – Kalenderquellen und Abruf

Ziel: echte Termine liegen in der Datenbank.

- [ ] Seite Kalenderquellen wie im Prototyp (anlegen, testen, pausieren, ersetzen, entfernen)
- [ ] Verschlüsselung der Links, `IcsFetcher` mit SSRF-Schutz
- [ ] `IcsParser` mit sabre/vobject: Zeitzonen, Ganztags-, Serien- und Ausnahmetermine
- [ ] Cronjob ruft fällige Quellen ab, Fehlerstatus pro Quelle
- [ ] Tests mit echten (anonymisierten) ICS-Dateien aus Outlook und Google
- [ ] Abnahme: alle vier Quellen aktuell; ein kaputter Link zeigt Fehler, alte Termine bleiben

### AP 3 – Leitstand

Ziel: der Dauer-Tab im Büro.

- [ ] Ansichten Tag, Woche, 2 Wochen, 4 Wochen nach Prototyp
- [ ] Große Uhrzeit mit Countdown zum nächsten Termin, Jetzt-Linie, Detailbereich, Quellen-Filter (Auswahl wird gemerkt); Zeitachse immer maßstabsgetreu, Farbe immer aus der Quelle; Quellen-Fehler nur als Hinweis, wenn einer vorliegt
- [ ] Blättern vor/zurück und „Heute“
- [ ] Aktualisierung im Hintergrund alle 5 Minuten, ohne Neuladen
- [ ] Abnahme: ein Arbeitstag mit dem Leitstand als Dauer-Tab ohne Kontowechsel

* [ ] Navigation responsiv: ab ca. 900 px Breite feste Seitenleiste (optional auf eine Symbolleiste einklappbar, Zustand pro Gerät gemerkt); darunter Hamburger-Menü mit Off-Canvas, gleicher Inhalt wie mobil (Hauptbereiche, Schnellzugriff, Kalenderquellen, Darstellung, Abmelden). Off-Canvas schließt per Escape, Tipp daneben und Wischen, Fokus bleibt im Menü, solange es offen ist.

### AP 4 – Erfassen und Inbox (PWA)

Ziel: Gedanken in zwei Schritten festhalten.

- [ ] Manifest, Service Worker, installierbar auf dem Pixel
- [ ] Mobiler Startbildschirm: oben Uhrzeit, Countdown und Erfassen (Textfeld, Sprechen per Tastatur-Diktat, Foto), darunter Kalender als Tag oder Liste mit Blättern; keine 2-/4-Wochen-Ansicht auf dem Handy
- [ ] Offline-Warteschlange für neue Einträge (mit `client_uuid`)
- [ ] App-Shortcuts und Share-Target für Links und Text
- [ ] Inbox mit Einsortieren als Aufgabe oder Notiz, Erfassungsfeld auch im Leitstand
- [ ] Abnahme: Notiz im Funkloch erfasst, kommt später genau einmal an

### AP 5 – Aufgaben und Notizen

- [ ] Aufgabenliste (Heute, Demnächst, Überfällig, nach Kontext), abhaken, wiederkehrend
- [ ] Notizen mit Markdown, Kontext, Suche über Titel und Text
- [ ] Aufgaben-Karte im Leitstand
- [ ] Schnellzugriff: Linkliste in den Einstellungen pflegen (Tabelle `quick_links`: Bezeichnung, URL, Kontext, Reihenfolge, optional Kalenderquelle); Anzeige am Desktop in der Seitenleiste, mobil im Menü; nur https, Symbol als Anfangsbuchstabe in Kontextfarbe statt externer Favicons, Öffnen in neuem Tab mit `noopener noreferrer`
- [ ] „In Outlook öffnen“ im Termindetail über den Link der Kalenderquelle; `login_hint` für das richtige M365-Konto vorab testen
- [ ] Datenexport als JSON

### AP 6 – Fotos

- [ ] Kamera-Aufnahme per `<input capture>`, Verkleinern im Browser
- [ ] Serverseitig neu codieren, EXIF entfernen, geschützte Auslieferung
- [ ] Abnahme: Foto vom Pixel enthält nach dem Upload keine Standortdaten

### AP 7 – Push-Erinnerungen

- [ ] VAPID-Schlüssel, Push-Abo pro Gerät, Testnachricht
- [ ] Cronjob versendet fällige Erinnerungen genau einmal
- [ ] optional: Hinweis X Minuten vor Terminen bestimmter Quellen
- [ ] Abnahme: Erinnerung kommt bei geschlossener App auf dem Pixel an

### AP 8 – Zeitfenster reservieren (nach dem MVP)

Ziel: Aus einer Idee oder Aufgabe wird mit wenigen Klicks ein Zeitblock im richtigen Kalender. Personal Hub schlägt freie Fenster über alle Konten vor, bleibt aber nur lesend: Angelegt wird der Termin im Ursprungskonto.

- [ ] Einstellungen: Arbeitszeiten, Puffer vor und nach Terminen, Zielkalender pro Kontext (Dienstlich und Betriebsrat → AWO, Ehrenamt → sysTelios, Privat → Google)
- [ ] `SlotFinder`: freie Fenster aus dem Termin-Cache aller aktiven Quellen, mit Tests für Randfälle
- [ ] Dialog „Zeit dafür reservieren“ an Aufgaben und Notizen (Dauer, Vorschläge, Zielkalender, neutraler Titel)
- [ ] Vorausgefüllte Links für Outlook im Web und Google Calendar, zusätzlich `.ics`-Datei als Rückfallweg; vorab im AWO-Tenant und auf dem Pixel testen
- [ ] Vorgemerkte Blöcke gestrichelt im Leitstand; nach dem nächsten Abruf automatisch als bestätigt erkannt
- [ ] Abnahme: Idee am Pixel einplanen, Termin erscheint nach dem Abruf bestätigt im Leitstand

## Arbeitsweise mit Cursor

Pro Arbeitspaket ein eigener Chat in Cursor, pro Teilschritt ein Prompt. Kleine Schritte halten die Ergebnisse überprüfbar.

1. **Plan zuerst:** Paket aus diesem Dokument einfügen und nur einen Plan anfordern (Dateien, Reihenfolge, offene Fragen). Plan prüfen, dann freigeben.
2. **Ein Teilschritt pro Prompt**, zum Beispiel: „Setze nur den IcsFetcher mit SSRF-Schutz um, inklusive Tests. Noch keine Oberfläche.“
3. **Selbst testen:** Tests laufen lassen (`vendor/bin/phpunit`), im Browser prüfen, am Pixel prüfen.
4. **Review vor dem Commit:** Cursor bitten, die eigenen Änderungen gegen die Sicherheits-Checkliste zu prüfen. Bei sicherheitsrelevanten Teilen (Login, Fetcher, Upload) zusätzlich ein frischer Chat, der nur liest und kritisiert.
5. **Commit pro Teilschritt** mit klarer Nachricht, Push nach GitHub.

Zur Modellwahl: Für Planung, Sicherheitsteile und das Parsen der ICS-Sonderfälle lohnt sich gezielt das stärkste verfügbare Modell statt „Automatik“. Für Templates und CSS reicht Automatik.

Vorlage für den Start eines Pakets:

```
Wir arbeiten an Personal Hub, Arbeitspaket <Nummer>: <Titel>.
Ziel und Abnahmekriterien: <aus dem Umsetzungskonzept einfügen>
Referenz für Gestaltung: docs/prototyp/<Datei>
Bitte zuerst nur einen Plan: betroffene Dateien, Reihenfolge der Teilschritte,
benötigte Migrationen, offene Fragen. Noch keinen Code schreiben.
```

## Offene Entscheidungen

Diese Punkte sollten vor dem jeweiligen Arbeitspaket entschieden sein. Alles andere klärt sich besser durch Nutzung.

| Frage | Vorschlag | Vor AP |
| --- | --- | --- |
| Eigenes PHP oder ProcessWire als Unterbau? | eigenes PHP (dieses Dokument geht davon aus) | 1 |
| Name und Subdomain | Arbeitstitel bleibt, Subdomain nicht erratbar | 1 |
| Repository öffentlich? | privat bis zur ersten stabilen Version | 1 |
| Was passiert, wenn ein M365-Konto kein ICS erlaubt? | Quelle fehlt; wichtige Termine notfalls als Aufgabe mit Datum | 2 |
| Wie weit zurück und voraus werden Termine gespeichert? | −30 bis +90 Tage | 2 |
| Standardansicht im Leitstand | Tag; Auswahl wird pro Gerät gemerkt | 3 |
| Wiederkehrende Aufgaben: welche Muster? | täglich, wöchentlich (Wochentag), monatlich (Tag) | 5 |
| Push auch vor Terminen? | nur für ausgewählte Quellen, 15 Minuten vorher | 7 |
