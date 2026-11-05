# Bericht zum Entwicklungsstand

Stand: 5. November 2026, Branch `master` (Commit `e979a2c`), Arbeitsverzeichnis mit einer fremden, uncommitteten Änderung an `docs/uebergabe.md` (Abschnitt „Rückmeldungen aus dem Probebetrieb“, nicht von dieser Prüfung).

Methode: Werkzeuge ausgeführt, Rauchtest mit dem eingebauten PHP-Server, Code gelesen (Kern, Auth, Kalender-Abruf, Medien, Push, Templates, CSS, JS). Nicht jede Datei wurde vollständig gelesen. Aussagen sind mit Fundstelle belegt; Vermutungen sind als solche gekennzeichnet. `config/config.php`, `config/keys/` und `storage/` wurden nicht geöffnet.

## 0. Hinweise zur Prüfgrundlage

Mehrere im Auftrag genannte Quellen existieren im Repository nicht:

| Genannt | Befund |
| --- | --- |
| `docs/projektplan.md` | nicht vorhanden (auch nicht auf `origin/*`) |
| `docs/prototyp/Feinschliff.dc.html` | nicht vorhanden; vorhanden sind `Main`, `Mobil`, `Quellen`, `Zeitfenster` |
| Ideen „Freie Zeit und Übergänge“ und „Horizont“ am Ende von `docs/uebergabe.md` | nicht vorhanden; die Datei endet mit „Weiterer Entwicklungsplan“ (2 Punkte) und, uncommittet, „Rückmeldungen aus dem Probebetrieb“ |
| AP 9 in `docs/umsetzungskonzept.md` | nicht vorhanden; AP 9 (Projekte) und eine Prioritätsstufe stehen nur in `docs/Personal Hub – Umsetzungskonzept für Cursor.md` |

Abschnitt 7 (Design) und Teile von Abschnitt 8 stützen sich deshalb auf die vorhandenen Tokens und die Beschreibung „filigraner“ aus `docs/uebergabe.md`, nicht auf die Referenzdatei.

Nebenwirkungen der Prüfung: Für die Datenbanktests lief kurz eine eigene MySQL-Instanz (Port 3307, Datenverzeichnis im Scratchpad, Socket unter `/tmp`; beendet, Socket gelöscht). Der Rauchtest hat in `storage/sessions/` anonyme Sitzungsdateien erzeugt (ignoriert von Git). Im Repository wurde nur dieser Bericht angelegt.

## 1. Zusammenfassung

Das Projekt ist weit über das Gerüst hinaus: AP 1 bis 8 sind im Code umgesetzt (180 PHP-Dateien, 36 Testdateien, 7 Migrationen), nach der Übergabe-Notiz in einem Branch, der inzwischen per Pull Request in `master` gemergt ist. PHPStan Level 6 und PHP-CS-Fixer sind sauber, `composer audit` meldet nichts, und mit Testdatenbank laufen 319 von 320 Tests. Die größten Stärken sind der konsequente SSRF-Schutz beim ICS-Abruf (IP-Pinning, jede Weiterleitung neu geprüft), die Auth-Schicht (Passwort plus TOTP mit Einmal-Code, Sperre, Gerätewiderruf) und die konsequent strikte CSP ohne Inline-Code. Die drei wichtigsten Probleme: (1) `ImageProcessor` fällt bei fehlgeschlagenem `imagescale` stillschweigend auf das Originalbild zurück, die 1600-px-Grenze wird dann nicht erzwungen (Test schlägt mit PHP 8.5 fehl). (2) Ohne erreichbare Datenbank erzeugt die Testsuite 82 Fehler statt Übersprünge; die CI läuft nur mit PHP 8.3, lokal läuft 8.5. (3) Zwei Konzeptdateien widersprechen sich (Cron, Datenbank, AP 9), und die Übergabe-Notiz ist teilweise veraltet; ohne Klärung ist unklar, was verbindlich ist. Offen sind weiterhin AP 0 (All-Inkl, Subdomain, Google-Link), jede Abnahme auf echten Geräten und der echten Kalender-Abruf.

## 2. Werkzeuge und Rauchtest

Umgebung: PHP 8.5.12 lokal (Projekt und CI: 8.3), Erweiterungen `curl`, `gd`, `intl`, `pdo_mysql`, `sodium` vorhanden.

| Werkzeug | Ergebnis |
| --- | --- |
| `composer validate` | gültig |
| `composer stan` (Level 6) | keine Fehler |
| `composer cs` (Prüfung) | 0 von 150 Dateien zu ändern (Hinweis: läuft auf PHP 8.5 statt 8.3) |
| `composer audit` (zusätzlich) | keine Sicherheitsmeldungen |
| `composer test`, ohne Datenbank | 402 Tests: 82 Fehler, 1 Fehlschlag, 165 übersprungen |
| `composer test`, mit lokaler MySQL 26.10 (`PH_TEST_DB_PORT=3307`) | 320 Tests, 923 Assertions, 1 Fehlschlag |

Befunde zu den Tests:

- **82 Fehler ohne Datenbank:** Der Test überspringt in `setUp()` (`tests/DbTestCase.php`), danach läuft `tearDown()` in mehreren Testklassen und greift auf nicht initialisierte typisierte Eigenschaften zu (z. B. `tests/Auth/SecurityPagesTest.php:55`, `tests/Notify/ReminderServiceTest.php:65`). In der CI fällt das nicht auf, weil dort MariaDB läuft.
- **1 Fehlschlag mit Datenbank:** `Tests\Media\ImageProcessorTest::testLargeImageIsScaledToMaxSideKeepingAspect`. Ursache: `imagescale(..., IMG_BICUBIC)` liefert unter PHP 8.5/GD 2.3.3 `false` (nachgestellt; `IMG_BILINEAR_FIXED` und `IMG_NEAREST_NEIGHBOUR` funktionieren). `src/Media/ImageProcessor.php:68-70` fällt dann auf das unverkleinerte Bild zurück.
- Getestet wurde gegen MySQL, die CI nutzt MariaDB 10.11 (`.github/workflows/ci.yml`). Kompatibilität mit MariaDB ist dadurch nur über die CI belegt.

Rauchtest (`php -S`, ohne Login, nur GET, ein POST ohne Token):

| Anfrage | Status | Anmerkung |
| --- | --- | --- |
| `/` | 303 | Weiterleitung zur Anmeldung |
| `/login` | 200 | neutraler Titel „Anmelden“, Cookie `ph_session` mit `HttpOnly; SameSite=Lax` (kein `Secure`, weil HTTP) |
| `/robots.txt` | 200 | `Disallow: /` |
| `/nicht-vorhanden`, `/inbox`, `/export.json`, `/media/1`, `/api/v1/items` | 404 | wie im Konzept |
| `/config/`, `/config/config.php`, `/storage/`, `/storage/logs/`, `/../config/config.php` | 404 | Webroot `public/` greift |
| `/sw.js`, `/manifest.webmanifest`, `/assets/css/tokens.css`, `/assets/fonts/OFL.txt` | 200 | |
| `POST /login` ohne CSRF-Token | 403 | |

Header auf PHP-Antworten: `X-Robots-Tag: noindex, nofollow, noarchive`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Cross-Origin-Opener-Policy`, `Permissions-Policy`, `Cache-Control: no-store`, CSP (`default-src 'none'`, nur `'self'`). HSTS fehlt im Rauchtest, weil HTTP; der Code setzt ihn nur bei HTTPS (`src/Core/SecurityHeaders.php:26`). Statische Dateien lieferte der eingebaute Server ohne jeden Sicherheitsheader aus; auf Apache übernimmt das `public/.htaccess` (`X-Robots-Tag`, `nosniff`, `Referrer-Policy`, HSTS, nur mit `mod_headers`, auf All-Inkl nicht geprüft).

## 3. Stand je Arbeitspaket

Grundlage: `docs/umsetzungskonzept.md`. „Umgesetzt“ heißt: im Code vorhanden und von Tests oder dem Rauchtest abgedeckt; echte Geräte und echte Konten sind überall noch nicht geprüft.

| AP | Status | Belege | Abweichungen und Offenes |
| --- | --- | --- | --- |
| 0 Vorab-Checks | teilweise | M365-ICS laut Anwender bestätigt (Commit `e979a2c`); `docs/deployment.md` | Google-Link, All-Inkl (PHP, Erweiterungen, Cron, Upload), Subdomain/HTTPS, Datenbank offen |
| 1 Gerüst, Login | umgesetzt | `src/Core/*`, `src/Auth/*`, `migrations/001_init.sql`, `bin/migrate.php`, `bin/create-user.php`, `templates/error/500.php`, `tests/Auth/*` | Abnahme (Pixel, All-Inkl) offen. Gerätewiderruf hat eine Oberfläche (`src/Auth/Module.php`, `templates/security/index.php`), die Übergabe-Notiz nennt sie noch als fehlend |
| 2 Kalenderquellen | umgesetzt | `src/Calendar/{IcsFetcher,UrlGuard,IpPolicy,CurlTransport,IcsParser,CalendarService,SourceController}.php`, `bin/cron.php`, `tests/fixtures/ics/` | Fixtures selbst geschrieben, nicht aus echten Konten (Konzept: anonymisierte Echtdateien); Abnahme „alle vier Quellen aktuell“ offen |
| 3 Leitstand | umgesetzt | `public/assets/js/leitstand.js`, `templates/leitstand.php`, `public/assets/js/nav.js` | keine automatisierten Browser-Tests; gemeldete UX-Mängel siehe Abschnitt 6 |
| 4 Erfassen, Inbox, PWA | umgesetzt | `src/Items/*`, `public/sw.js`, `manifest.webmanifest` (`shortcuts`, `share_target`), `capture.js` | Offline-Warteschlange in `localStorage` statt Service Worker (ADR 0005); Pixel-Abnahme offen |
| 5 Aufgaben, Notizen | überwiegend | `src/Items/{TaskService,Recurrence,MarkdownRenderer}.php`, `src/Links/*`, `/export.json` | „In Outlook öffnen“ im Termindetail und Verknüpfung Schnellzugriff–Quelle nicht umgesetzt |
| 6 Fotos | umgesetzt, mit Mangel | `src/Media/*`, `tests/Media/*` | Verkleinern kann stillschweigend entfallen (Abschnitt 4, S-01) |
| 7 Push | Logik umgesetzt | `src/Notify/*`, `bin/cron.php`, `public/assets/js/push.js` | Zustellung auf echtes Gerät nicht geprüft; `gmp`/`bcmath` bei All-Inkl ungeprüft |
| 8 Zeitfenster (nach MVP) | Kern umgesetzt | `src/Calendar/{SlotFinder,PlanningService,PlanningController,OutlookWebLinkBuilder,GoogleCalendarLinkBuilder}.php`, `migrations/007_planning.sql` | Links nicht gegen echten Tenant/Handy getestet; vorgemerkte Blöcke fehlen in 2-/4-Wochenansicht |
| 9 Projekte | nicht umgesetzt | nur in `docs/Personal Hub – Umsetzungskonzept für Cursor.md`; keine Tabelle, keine Prioritätsspalte in `migrations/` | AP 9 und Priorität fehlen in `docs/umsetzungskonzept.md` |

## 4. Sicherheit

Prüfung der Checkliste aus dem Konzept. Schwere bezieht sich auf das Risiko im Betrieb für eine Einzelperson auf Shared Hosting.

| Punkt | Status | Fundstelle | Schwere |
| --- | --- | --- | --- |
| Login Passwort plus TOTP, `password_hash` | erfüllt; Mindestlänge 12, Einmal-Code (`consumeTotpStep`), gleicher Aufwand bei unbekanntem Nutzer | `src/Auth/AuthService.php` | – |
| Sperre nach 5 Fehlversuchen / 15 min | erfüllt (je IP); zusätzlich 20 je Konto | `AuthService.php:18-20`, `LoginAttemptRepository.php` | – |
| Session-ID nach Login erneuern | erfüllt, ebenso CSRF-Token | `AuthService::completeLogin` | – |
| Cookies `Secure`, `HttpOnly`, `SameSite=Lax` | erfüllt; `Secure` hängt an `app.url` bzw. HTTPS-Erkennung | `src/Core/Session.php:46-52`, `AuthController.php` | – |
| „Gerät merken“ 30 Tage, Hash, widerrufbar | erfüllt | `TrustedDeviceRepository.php`, `SecurityController.php` | – |
| CSRF für jede ändernde Anfrage inkl. JSON | erfüllt (Kernel prüft zentral vor dem Handler, Token per Feld oder Header) | `src/Core/Kernel.php:106`, `Csrf.php` | – |
| Ausgabe escapen, Markdown ohne HTML | erfüllt; einziges Roh-Echo ist `$html` aus `MarkdownRenderer` (`html_input: strip`, `allow_unsafe_links: false`) | `templates/notes/edit.php:43`, `MarkdownRenderer.php` | – |
| ICS-Links mit libsodium verschlüsseln, Oberfläche nur Hinweis | erfüllt laut Übergabe und `Crypto` (secretbox); Fundstelle der Speicherung nicht einzeln geprüft | `src/Core/Crypto.php`, `CalendarService` | – |
| ICS-Abruf: https, keine privaten IPs, 3 Weiterleitungen, 10 s, 5 MB | erfüllt; Verbindung per `CURLOPT_RESOLVE` an geprüfte IP gebunden, Größenlimit nach Dekompression, jede Weiterleitung neu geprüft | `UrlGuard.php`, `IpPolicy.php`, `IcsFetcher.php`, `CurlTransport.php` | – |
| IP-Sperrliste vollständig | Lücken im Detail: 6to4 `2002::/16` und `64:ff9b:1::/48` nicht ausdrücklich gesperrt | `IpPolicy.php:20-32` | niedrig |
| Fotos: Typ per Inhalt, neu codieren, 1600 px, Ablage, Auslieferung nur nach Login | teilweise: Typ, Pixelgrenze, Neucodierung und Login-Pflicht vorhanden; **Verkleinerung entfällt still bei `imagescale`-Fehler** | `src/Media/ImageProcessor.php:60-72`, `MediaController.php`, `LocalMediaStorage.php` | mittel (S-01) |
| Logs ohne Inhalte | erfüllt: nur Ereignisname, Typ, Datei:Zeile | `src/Core/Logger.php` | – |
| Security-Header, CSP ohne externe Quellen | erfüllt für PHP-Antworten; statische Dateien nur über `.htaccess` mit `mod_headers` | `SecurityHeaders.php`, `public/.htaccess` | niedrig (S-02) |
| `robots.txt`, `noindex` | erfüllt | `public/robots.txt`, Header | – |
| Ohne Login nur Login, Manifest, SW, Assets; sonst 404 | erfüllt (Rauchtest, `Router::AUTH_REQUIRED` liefert 404) | `Kernel.php:96-101` | – |
| Beschreibung/Teilnehmer nicht speichern (Regel seit `e979a2c`) | erfüllt: Parser liest nur `SUMMARY`, `LOCATION` und Zeiten | `src/Calendar/IcsParser.php:90-98` | – |
| Push-Endpunkte | erfüllt: Host-Allowlist per Endung, nur https; Versand ohne Weiterleitungen, Antwort höchstens 64 KB | `SubscriptionService.php:56-75`, `Notify/Http/CurlClient.php` | – |
| Verschlüsseltes Backup, Wiederherstellung getestet | nur dokumentiert, nicht prüfbar | `docs/deployment.md` | mittel (organisatorisch) |

Weitere Sicherheitsbefunde:

| Nr. | Befund | Fundstelle | Schwere |
| --- | --- | --- | --- |
| S-01 | Stilles Zurückfallen auf das Originalbild bei `imagescale === false`; das Größenlimit von 1600 px und der Speicherbedarf der Ablage hängen von der GD-Version ab. Besser: ablehnen oder anderen Modus nutzen. | `src/Media/ImageProcessor.php:68-70` | mittel |
| S-02 | HSTS, `nosniff` und `X-Robots-Tag` für statische Dateien hängen allein an `mod_headers`; ohne Modul fehlen sie. Der Service Worker (`sw.js`) hat dann kein `no-cache`. | `public/.htaccess` | niedrig |
| S-03 | `Request::isHttps()` vertraut dem Header `X-Forwarded-Proto` ungeprüft; wirkt auf das `Secure`-Flag des Geräte-Cookies und HSTS. Der Aufrufer kontrolliert nur seine eigene Antwort. | `src/Core/Request.php` | niedrig |
| S-04 | Sitzungsdateien in `storage/sessions/` mit eigenem Pfad: Ob die Bereinigung alter Dateien auf dem Zielhost läuft, ist vom Server abhängig (Vermutung; bei Distributionen mit `gc_probability=0` ggf. nicht). | `src/Core/Session.php:35-47` | niedrig |
| S-05 | Schlüssel `config/keys/app.key` und Datenbank liegen auf demselben Server; Verlust des Schlüssels macht ICS-Links und TOTP-Geheimnisse unlesbar. Backup-Pflicht ist dokumentiert, aber nicht automatisiert. | `docs/deployment.md` | mittel (Betrieb) |
| S-06 | Lokale `config/config.php` ist `0644`. Auf dem Server sollte sie nur für den Webbenutzer lesbar sein. Das Konzept nennt es nicht. | Dateirechte (Metadaten) | niedrig |
| S-07 | Es gibt keine Abhängigkeitsprüfung in der CI (`composer audit` fehlt in `.github/workflows/ci.yml`). | `ci.yml` | niedrig |
| S-08 | Die Sperre ist die einzige Bremse gegen Durchprobieren (kein Captcha, wie in der Übergabe vermerkt). Pro Konto sind 20 Fehlversuche je 15 Minuten möglich, das zweite Merkmal TOTP begrenzt das Risiko. | `AuthService.php` | niedrig |

## 5. Architektur und Codequalität

Positiv: Modulvertrag (`ModuleInterface`, `config/modules.php`) und eigener Container wie im Konzept; Controller sind überwiegend dünn; die einzige globale Funktion ist `e()` (`src/Core/helpers.php`); Zeiten werden in UTC geführt (`Kernel.php:25`); PHPStan Level 6 grün; Tests in fast jedem Modul (36 Testdateien, `AuthService` über `AuthFlowTest`).

| Nr. | Befund | Fundstelle |
| --- | --- | --- |
| A-01 | SQL außerhalb von Repositories: `QuickLinkService` und `MediaService` greifen direkt auf `Db` zu. | `src/Links/QuickLinkService.php:31-109`, `src/Media/MediaService.php:37-96` |
| A-02 | Modul-Kreisabhängigkeit: `Items` nutzt `Media\MediaService`, `Media` nutzt `Items\ItemService`. | `src/Items/ItemController.php`, `src/Media/MediaController.php` |
| A-03 | Module sprechen ohne Interface miteinander: `Calendar` und `Notify` nutzen `Items\TaskService`, `Notify` nutzt `Calendar\CalendarService`, `Links` und das Layout nutzen `Calendar\Palette`. Das Konzept verlangt Service-Interfaces. `Palette` gehört eher in den Kern. | `src/Calendar/PlanningController.php`, `src/Notify/*`, `templates/layout.php` |
| A-04 | Tabellenzugriffe über Modulgrenzen: `Items\ItemRepository` liest und schreibt `reminders` (laut Konzept Notify), `Calendar` liest `contexts` neben `Core\ContextRepository` (in der Übergabe als Doppelung vermerkt). | `src/Items/ItemRepository.php`, `src/Calendar/SourceRepository.php` |
| A-05 | Controller greifen direkt auf Repositories zu, ohne Service: `SourceController`, `AgendaController` (auch `EventRepository`), `PushController`, sowie `ContextRepository` in mehreren Controllern. | `src/Calendar/AgendaController.php:19-20`, `src/Notify/PushController.php:20` |
| A-06 | `ModuleInterface::navItems()` und `Kernel::navItems()` sind vorhanden, werden aber nicht genutzt; die Navigation steht fest in `templates/layout.php`. Neue Menüpunkte müssen an zwei Stellen gedacht werden. | `src/Core/Kernel.php:68-77`, `templates/layout.php:30-41` |
| A-07 | Testbarkeit: Ohne Datenbank brechen Tests statt zu überspringen (Abschnitt 2). 165 Tests hängen an der Datenbank; kein Coverage-Lauf in der CI (`coverage: none`). | `tests/DbTestCase.php`, `ci.yml` |
| A-08 | CI testet nur PHP 8.3, lokal läuft 8.5; das GD-Verhalten unterscheidet sich (S-01). | `.github/workflows/ci.yml` |
| A-09 | Größere Dateien: `public/assets/css/views.css` (1236 Zeilen) und `public/assets/js/leitstand.js` (470 Zeilen) bündeln viele Ansichten; JS ohne Tests. | `public/assets/` |
| A-10 | `docs/uebergabe.md` ist teilweise veraltet (Branch „noch nicht gemergt“, Gerätewiderruf „Oberfläche fehlt“). | `docs/uebergabe.md:3`, Abschnitt „Erledigt“ |

## 6. Frontend, UX und Barrierefreiheit

Positiv: Tokens werden durchgängig genutzt (nur 2 fest codierte Farben in `components.css`/`views.css`); Atkinson-Schrift lokal eingebunden, `font-src 'self'`; keine externen Anfragen in Templates, CSS, JS und Service Worker; keine Inline-Styles und -Skripte in Templates; im JS kein `innerHTML`; alle Formulare mit `label`; Fokusring 3 px (`base.css:53`); Navigation mit `aria-current`, `aria-expanded`, `aria-controls`; Statusmeldungen mit `role="status"`; Hell, Dunkel und Auto vorhanden.

| Nr. | Befund | Fundstelle |
| --- | --- | --- |
| F-01 | Schriftgrößen unter 13 px, entgegen der Konzeptregel „nie unter 13 px“: 11 px an drei, 12 px an zwei Stellen. | `public/assets/css/views.css:190, 345, 399, 441, 1137` |
| F-02 | Touch-Ziele unter 44 px: kleine Buttons mit `min-height: 36px`, u. a. die Navigations-Schaltflächen des Leitstands (`btn--small`). | `public/assets/css/components.css:95, 113`, `templates/leitstand.php:25-27` |
| F-03 | `prefers-reduced-motion` nur in `base.css` berücksichtigt, nicht in `components.css`/`views.css`. | `public/assets/css/base.css` |
| F-04 | Dunkle Tokens stehen doppelt (`@media` und `[data-theme="dark"]`); jede Farbänderung muss zweimal erfolgen. | `public/assets/css/tokens.css:62-120` |
| F-05 | Quellen im Kalenderraster unterscheiden sich vor allem durch Farbe (`src-*`-Klassen); der Name steht in Filter-Chips und im Detail. Ob das im Raster ausreicht, ist offen (Vermutung: für Rot-Grün-Schwäche schwach). | `public/assets/js/leitstand.js:405-406`, `Palette.php` |
| F-06 | Navigationssymbole sind Buchstaben, daher nur mit Text verständlich; im eingeklappten Menü hilft nur das `title`-Attribut. | `templates/layout.php:62` |
| F-07 | Tagesansicht zeigt nach Wochen-Navigation den Montag: `shift()` setzt den Anker in Mehrtagesansichten auf den Wochenanfang, der Wechsel auf „Tag“ übernimmt ihn unverändert (aus dem Code abgeleitet, nicht im Browser nachgestellt). Nach dem Neuladen gilt wieder heute. | `public/assets/js/leitstand.js:79, 149, 444` |
| F-08 | Zwei- und Vier-Wochenansicht verwenden dieselbe Zellenlogik (`DAYS = {w2: 14, w4: 28}`), die 2-Wochen-Ansicht nutzt die Fläche nur durch weniger Reihen. | `leitstand.js:19, 359-367` |
| F-09 | In kurzen Terminen wird der Titel mit `text-overflow: ellipsis` einzeilig abgeschnitten (gemeldet für 30-Minuten-Termine); Termindetail erscheint in einem eigenen Bereich, den man ggf. erst per Scrollen erreicht. | `public/assets/css/views.css:550`, `leitstand.js:397-425` |
| F-10 | Keine automatisierten Browser- oder Barrierefreiheitstests; Fokusführung im Off-Canvas-Menü ist laut Übergabe nur manuell geprüft. | `docs/uebergabe.md` |

## 7. Design: Abstand zur Feinschliff-Richtung

Die Referenzdatei `Feinschliff.dc.html` liegt nicht im Repository. Die folgende Einschätzung beschreibt deshalb nur, wo die heutigen Tokens und Komponenten „schwer“ wirken und welche Hebel zentral wären. Sie setzt voraus, dass „filigraner“ weniger Rahmen, kleinere Radien und leichtere Gewichte meint (laut `docs/uebergabe.md`).

Heutige Bausteine:

| Stelle | Wert | Datei |
| --- | --- | --- |
| Karten | 1 px Rahmen plus Schatten plus Innenabstand 1,5 rem | `components.css` (`.card`) |
| Radien | `--radius: 10px`, `--radius-sm: 6px`, 28 `border-radius`-Deklarationen | `tokens.css`, `components.css`, `views.css` |
| Schatten | `0 1px 2px` und `0 4px 16px` bei 6 % | `tokens.css` (`--shadow`), 4 Verwendungen |
| Gewichte | 600 an 14, 500 an 7, 700 an 1 Stelle | `components.css`, `views.css` |
| Bedienelemente | Buttons und Eingabefelder 44 px hoch, Rahmen 1 px | `components.css` |
| Rahmenlinien | 9 Deklarationen `border: 1px` plus Tokens `--line`, `--line2`, `--border` | `components.css`, `views.css` |

Hebel, nach Reichweite:

1. **Tokens** (`tokens.css`): `--radius`, `--radius-sm`, `--shadow`, Linienfarben `--line`/`--border` und ein neues Gewicht-Token (z. B. `--weight-strong`) würden die Optik an vielen Stellen auf einmal ändern. Dafür müssten die 14 festen `font-weight: 600` auf ein Token zeigen.
2. **Komponenten** (`components.css`): `.card` (Rahmen oder Schatten statt beides), `.btn` (Rahmen/Füllung), Eingabefelder und das Segment-Steuerelement (`.segment`) liefern den größten sichtbaren Unterschied bei Bedienelementen.
3. **Ansichten** (`views.css`): Kalenderraster, Ereignisblöcke, Chips und die Aufgaben-/Notizlisten tragen die meisten Kästen; sie sind der größte Block (1236 Zeilen).
4. Vor dem Umbau die dunklen Tokens zusammenführen (F-04), sonst doppelte Arbeit.

Einschränkung: Die Mindestgrößen (Text 13 px, Touch-Ziele 44 px, siehe F-01 und F-02) sollten bei „filigraner“ nicht unterschritten werden; die heutige Barrierefreiheit ist ein Hauptziel des Projekts (Schriftwahl).

## 8. Ideen aus der Übergabe

Quelle: `docs/uebergabe.md`, Abschnitte „Weiterer Entwicklungsplan“ (committet) und „Rückmeldungen aus dem Probebetrieb“ (uncommittet). Die Ideen „Freie Zeit und Übergänge“ und „Horizont“ existieren dort nicht (Abschnitt 0). Aufwand: S klein, M mittel, L groß.

| Nr. | Idee | Bezug im Code | Betroffene Dateien | Abhängigkeiten | Aufwand | Empfehlung |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Installation per Browser, Release-ZIP mit `vendor/` | heute nur CLI: `bin/create-key.php`, `migrate.php`, `create-user.php`, `create-vapid.php`; `Migrator` wiederverwendbar | neue Installer-Route, `bin/*`, `docs/deployment.md`, GitHub-Release-Workflow | Entscheidung „Produkt für andere“ statt „eine Person“; Idee 2 | L | später: erst manuelles Deployment auf All-Inkl abschließen (AP 0); Release-ZIP per Workflow vorher (S) |
| 2 | Erster Nutzer samt TOTP im Browser | `UserRepository`, `TotpVerifier`, `AuthService` | neue Installer-Seiten, Templates | QR-Code braucht Bibliothek (neues Paket, Rückfrage nach Regeldatei) oder eigene SVG-Erzeugung; Sperrmechanismus nach Einrichtung | M | später, zusammen mit 1 |
| 3 | Termindetails ohne Sprung nach oben | `renderDetail()`, Detail-Box im Template | `leitstand.js:397-425`, `templates/leitstand.php`, `views.css` | keine | S | jetzt: Detail neben oder unter dem Termin bzw. mitscrollend |
| 4 | Text in 30-Minuten-Terminen nicht abschneiden | Ereignisblock `.ev--line` | `views.css:527-552`, `leitstand.js` | Rasterhöhe `HOUR_PX = 56` | S | jetzt |
| 5 | Agenda-Ansicht | `AgendaController::events` liefert bereits JSON; mobile „Liste“ steht im Konzept (AP 4) | `leitstand.js`, `views.css`, ggf. neue Route | keine | M | jetzt (nützlich auch mobil) |
| 6 | Tagesansicht zeigt Montag | siehe F-07 | `leitstand.js:79, 149, 444` | keine | S | jetzt (Fehlerkorrektur) |
| 7 | 2-Wochen-Ansicht besser nutzen | gleiche Zellen wie 4 Wochen (F-08) | `leitstand.js:359-367`, `views.css` | mit 14 (Gestaltung) abstimmen | S–M | später, mit der Gestaltungsrunde |
| 8 | Jahresüberblick | nicht vorhanden; Datenbestand nur −30 bis +90 Tage (Konzept, `CalendarService`) | neue Ansicht, `CalendarService`, `EventRepository` | Erweiterung des Abrufzeitraums und der Datenmenge | L | später; Konflikt mit „keine Vergangenheit über 30 Tage“ |
| 9 | Monatsübersicht im eingeklappten Menü, nur im Kalender, ausblendbar | Seitenleiste mit Einklappzustand (`nav.js`, `layout.php`) | `nav.js`, `layout.php`, `views.css`, `leitstand.js` | Idee 13 (Kalender als eigene Seite), Datenzeitraum | M | später, nach 13 |
| 10 | Termine schreiben | heute nur lesend (ADR 0003); AP 8 schreibt über vorausgefüllte Links | `CalendarProviderInterface` (im Konzept), `Calendar/*`, Sicherheitsmodell | Zugangsdaten oder OAuth für M365/Google (Vermutung: bei M365 Tenant-Zustimmung nötig), neue Geheimnisse im Speicher | L | später; erst AP 8 im Alltag nutzen, dann per neuem ADR entscheiden, ob „eigene lokale Termine“ reichen |
| 11 | Feste Kopfzeile mit Uhr und Datum auf allen Seiten | Uhr nur im Leitstand-Hero, mobil eine `mobilebar` | `templates/layout.php`, `time.js`, `views.css` | Idee 13; Platzbedarf vs. Kalenderfläche | S | jetzt, gemeinsam mit 13 |
| 12 | Aufgaben, Notizen, Erfassen, Inbox klarer abgrenzen | vier Menüeinträge, ein Datenmodell (ADR 0002) | `layout.php`, Templates unter `templates/items`, `tasks`, `notes` | Pixel-Abnahme AP 4; Datenmodell bleibt | S–M | jetzt als Entscheidung und Texte; Vorschlag: „Erfassen“ und „Inbox“ zu einem Eingang |
| 13 | Kalender als eigener Menüpunkt, Leitstand wird Dashboard, Erfassungsfeld nur dort | `/` rendert Leitstand mit Kalender, `start_url` ist `/` | `Calendar/Module.php`, `templates/leitstand.php`, `manifest.webmanifest`, `leitstand.js` | Voraussetzung für 9 und 11; Konzept (AP 3) nennt den Leitstand als Dauer-Tab | M | jetzt: Strukturentscheidung zuerst |
| 14 | Filigranere Gestaltung | Tokens und Komponenten (Abschnitt 7) | `tokens.css`, `components.css`, `views.css` | Referenzdatei fehlt; F-04 vorher auflösen | M | später, nach 13 und nach Eingang der Referenz |

Konflikte:

- 13 ändert, was im Konzept „Leitstand“ heißt (Dauer-Tab für den Kalender); Konzept und Prototyp `Main.dc.html` müssten angepasst werden.
- 9 und 11 hängen an 13 und konkurrieren mit dem Wunsch nach maximaler Kalenderfläche.
- 8 widerspricht dem Zeitraum im Konzept (−30 bis +90 Tage) und der Regel „nicht in die Vergangenheit über 30 Tage“.
- 10 widerspricht ADR 0003 (nur lesend) und dem Sicherheitsmodell; AP 8 deckt einen Teil bereits ab.
- 1 und 2 öffnen vor der Einrichtung eine Installationsroute; das widerspricht dem Grundsatz „ohne Login nur Anmeldung und 404“, und wer die URL zuerst aufruft, würde zum Administrator. Ein Sperrmechanismus nach der Einrichtung ist Pflicht.
- 1 und 2 verändern den Charakter des Projekts von „persönliches Werkzeug“ zu verteilbarem Produkt (Lizenz MIT, Wunsch „wie WordPress“).

## 9. Abweichungen zwischen Konzept und Code, die eine Entscheidung brauchen

| Nr. | Abweichung | Entscheidung |
| --- | --- | --- |
| E-01 | Zwei Konzeptdateien: `docs/umsetzungskonzept.md` (laut `README.md` verbindlich) und `docs/Personal Hub – Umsetzungskonzept für Cursor.md` mit AP 9, Priorität, minütlichem HTTPS-Cron, SQLite-Vorschlag und PHP-8.5-Aussage. Beide wurden zuletzt am 3./4. November geändert. Die frühere Empfehlung, die zweite Datei zu löschen, war voreilig. | Eine Datei als verbindlich festlegen, Unterschiede übernehmen oder verwerfen |
| E-02 | Cron: Konzept A nennt `bin/cron.php` alle 15 Minuten, Konzept B einen geschützten HTTPS-Endpunkt jede Minute. Im Code existiert nur das CLI-Skript. | Welcher Weg bei All-Inkl? Davon hängt die Genauigkeit der Push-Erinnerungen ab |
| E-03 | Datenbank: Konzept A MariaDB, Konzept B MariaDB (Server) und MySQL (lokal), plus Vorschlag SQLite „vor AP 1“. Code und CI nutzen MariaDB/MySQL, die Migrationen sind MySQL-Dialekt. | Bei MariaDB bleiben (Code anpassen entfällt) oder SQLite einführen (Migrationen, Migrator und Tests umbauen, Vermutung: erheblicher Aufwand) |
| E-04 | PHP-Version: Code und CI 8.3, lokale Entwicklung 8.5, Konzept B nennt 8.5 auf dem Server. | CI um 8.5 erweitern oder 8.3 lokal erzwingen; `composer.json` hält `platform.php` auf 8.3 |
| E-05 | Offline-Warteschlange im Browser-`localStorage` statt im Service Worker (ADR 0005) | Konzept anpassen (Code folgt dem ADR) |
| E-06 | Schrift: zwei Dateien statt einer | Konzept anpassen (in der Übergabe vermerkt) |
| E-07 | `navItems()` im Modulvertrag, Navigation tatsächlich im Layout | Code anpassen (Menü aus Modulen) oder Vertrag streichen |
| E-08 | Schichtenregel („SQL nur in Repositories“, „Module nur über Service-Interfaces“) wird in A-01 bis A-05 verletzt | Regel lockern oder Code umbauen |
| E-09 | Konzept verlangt Schriftgröße mindestens 13 px; CSS hat 11 und 12 px (F-01) | Code anpassen |
| E-10 | `docs/uebergabe.md` ist veraltet (Branch-Stand, Gerätewiderruf) | Dokument aktualisieren |
| E-11 | Priorität an Aufgaben/Notizen und AP 9 stehen nur im zweiten Konzept, im Code gibt es weder Spalte noch Tabelle | mit E-01 entscheiden |

## 10. Empfohlene Reihenfolge der nächsten Arbeitspakete

Jedes Paket ist für einen eigenen Cursor-Chat geschnitten (Plan zuerst, ein Commit je Teilschritt).

| Nr. | Paket | Ziel | Abnahmekriterium |
| --- | --- | --- | --- |
| 1 | Test- und Bildverarbeitungs-Härtung (S) | Tests sauber ohne Datenbank, Bildgrenze nicht umgehbar, Abhängigkeitsprüfung | `composer test` ohne Datenbank: nur Skips, keine Fehler; `ImageProcessor` lehnt ab oder verkleinert immer (kein Originalbild über 1600 px); `composer audit` in der CI; CI läuft mit PHP 8.3 und 8.5 |
| 2 | Konzept- und Dokumentenabgleich (S, nur Doku) | Eine verbindliche Planung | E-01 bis E-06 und E-10 entschieden und in einer Datei nachgeführt; `uebergabe.md` aktuell |
| 3 | AP 0 abschließen und erster Deploy (Anwender, S) | Betrieb auf All-Inkl | Subdomain mit HTTPS, `docs/deployment.md`-Prüfliste erfüllt, Cron läuft, alle Quellen zeigen „Aktuell“, Login am Pixel, Header auch auf statischen Dateien |
| 4 | Kalender-Korrekturen (S–M) | Alltagsfehler aus dem Probebetrieb beseitigen | Tag zeigt heute nach jeder Navigation; Detail ohne Scrollen sichtbar; 30-Minuten-Termine lesbar; Agenda-Ansicht vorhanden |
| 5 | Struktur: Kalender-Seite, Dashboard, feste Kopfzeile (M) | Klare Rollen von Leitstand und Kalender | `/kalender` mit voller Fläche; Leitstand als Überblick mit Erfassungsfeld; Uhr und Datum auf allen Seiten; Prototypen und Konzept nachgeführt |
| 6 | Abgrenzung Aufgaben/Notizen/Inbox (S–M) | Weniger Einstiege, klare Texte | Menü reduziert, Datenmodell unverändert, Erfassung am Pixel in höchstens zwei Schritten |
| 7 | Gestaltungsdurchgang über Tokens (M) | Filigranere Optik | Dunkle Tokens einmal geführt; Radien, Rahmen, Gewichte über Tokens; Mindestgrößen 13 px und 44 px bleiben; Hell, Dunkel, 390 px geprüft |
| 8 | Barrierefreiheit (S) | F-01 bis F-03, F-05 | keine Schrift unter 13 px; Touch-Ziele 44 px; `prefers-reduced-motion` überall; Quellen zusätzlich zu Farbe erkennbar |
| 9 | Pixel-Abnahmen AP 4, 7, 8 (Anwender, S) | Funktion auf echten Geräten | Notiz im Funkloch genau einmal; Erinnerung bei geschlossener App; Outlook- und Google-Links öffnen im richtigen Konto |
| 10 | Architektur-Aufräumen (M) | Schichten und Module sauberer | Repositories für Schnellzugriff und Medien; Items/Media ohne Kreis; `Palette` im Kern; Navigation aus `navItems()` |
| 11 | Monatsübersicht und 2-Wochen-Layout (M) | Navigation im Kalender | Mini-Monat nur auf der Kalenderseite, ausblendbar, ohne dauerhafte Verengung |
| 12 | Installer und Release (L, nur nach Entscheidung „Produkt“) | Einrichtung ohne Terminal | Release-ZIP mit `vendor/`; Browser-Einrichtung mit TOTP-Pflicht; Route danach gesperrt; Sicherheitsprüfung durch frischen Review-Chat |
| 13 | AP 9 Projekte und Priorität (nach Entscheidung E-01/E-11) | Vorhaben bündeln | wie im zweiten Konzept beschrieben |

## 11. Offene Fragen an dich

1. Welche der beiden Konzeptdateien ist verbindlich, und gehören AP 9, Priorität und der Cron-Endpunkt zum Plan?
2. Wie wird der Cron bei All-Inkl angestoßen: CLI-Skript oder geschützter HTTPS-Aufruf, und in welchem Takt?
3. Bleibt es bei MariaDB, oder soll SQLite (Konzept B) kommen?
4. Wo liegen `docs/projektplan.md`, `Feinschliff.dc.html` und die Ideen „Freie Zeit und Übergänge“ und „Horizont“? Sie fehlen im Repository und auf `origin`.
5. Soll Personal Hub ein verteilbares Produkt (Installer, Release-ZIP) werden oder ein persönliches Werkzeug bleiben?
6. Wie sollen Kalender und Leitstand getrennt werden (Idee 13), und gilt der Wunsch „möglichst viel Fläche“ auch auf kleineren Bildschirmen?
7. Soll Idee 10 (Termine schreiben) überhaupt weiterverfolgt werden, oder genügen die Links aus AP 8?
8. Soll die uncommittete Änderung an `docs/uebergabe.md` in den Bericht-Commit oder getrennt committet werden?
