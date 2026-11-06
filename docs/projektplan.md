# Projektplan

Stand: 6. November 2026. Verbindlich für die Reihenfolge ist dieses Dokument, für die fachlichen Festlegungen [das Umsetzungskonzept](umsetzungskonzept.md). Status: offen, in Arbeit oder erledigt.

## Phase 1 – Ordnung

### Dokumentenabgleich

- **Ziel:** Konzept, ADRs, Projektplan, Deployment, Übergabe und README sagen dasselbe.
- **Begründung:** Zwei Stände und offene Entscheidungen haben den nächsten Bau blockiert.
- **Abnahme:** MariaDB, Cron, PHP-Versionen, Offline-Warteschlange, Schriftdateien, Installer, Kalender/Leitstand und die späteren Optionen stehen im Konzept. ADRs 0006 bis 0009 existieren. Die Übergabe verweist auf diesen Plan.
- **Status:** erledigt

## Phase 2 – Betrieb

### Härtung und Cron-Endpunkt

- **Ziel:** Tests und Bildgrenze sind verlässlich, die CI prüft PHP 8.3 und 8.5, der geschützte Cron-Endpunkt existiert.
- **Begründung:** Erinnerungen und der Abruf brauchen einen Minuten-Takt, bevor die Anwendung auf dem Server liegt.
- **Abnahme:** `composer test` ohne Datenbank ergibt nur übersprungene Tests, keine Fehler. `ImageProcessor` lehnt zu große Bilder ab oder verkleinert sie, nie ein Original über 1600 px. `composer audit` läuft in der CI. Die CI läuft mit PHP 8.3 und 8.5. Der Endpunkt `/cron/{token}` antwortet bei falschem Token mit 404 und ohne gültigen HTTP-Zugang mit 401. Mit beidem startet er dieselbe Logik wie `bin/cron.php`.
- **Status:** erledigt

### Installer und Release-ZIP

- **Ziel:** Eine frische Installation entsteht aus dem Release-ZIP im Browser, ohne Terminal und ohne Composer auf dem Server.
- **Begründung:** Auf dem Webhosting gibt es beides nicht, der Authenticator bleibt aber Pflicht.
- **Abnahme:** Ohne Einrichtungscode ist keine Installation möglich. Nach der Installation liefert die Installer-Route 404, auch bei erneutem Aufruf und nach Löschen der Sperrdatei, solange `config/config.php` existiert. Eine frische Subdomain ist mit dem Release-ZIP ohne Terminal einsatzbereit. Der QR-Code kommt aus einem kleinen etablierten Paket, das in diesem Paket nach Rückfrage gewählt wird. Das TOTP-Geheimnis steht zusätzlich als Text auf der Seite. Die Cron-Adresse wird samt Token angezeigt.
- **Status:** offen

### Sicherheitsprüfung des Installers

- **Ziel:** Ein unabhängiger Review prüft nur den Installer gegen die Sicherheitsanforderungen.
- **Begründung:** Die Installationsroute ist vor dem ersten Login der empfindlichste Zugang.
- **Abnahme:** Ein frischer Review-Chat findet keine offenen Befunde, bevor das Deployment beginnt.
- **Status:** offen

### Erstes Deployment auf All-Inkl

- **Ziel:** Die Anwendung läuft auf einer Subdomain und wurde über den Installer eingerichtet.
- **Begründung:** Erst der echte Server zeigt, ob PHP, Cron, HTTPS und der Dokumentenstamm zusammenpassen.
- **Abnahme:** Subdomain mit HTTPS, Dokumentenstamm `public/`, Prüfliste aus [deployment.md](deployment.md) erfüllt, Cron läuft, die Quellen zeigen „Aktuell“, Login auf dem Handy klappt, die Sicherheits-Header gelten auch für statische Dateien.
- **Status:** offen

### Abnahmen am Pixel

- **Ziel:** Erfassen, Push und die Kalender-Links bestehen auf dem Gerät.
- **Begründung:** Browser-Tests ersetzen die Abnahme auf dem Handy nicht.
- **Abnahme:** Eine Notiz im Funkloch kommt genau einmal an. Eine Erinnerung kommt bei geschlossener App an. Die Links zu Outlook im Web und zu Google Kalender öffnen das richtige Konto.
- **Status:** offen

## Phase 3 – Alltag

### Kalender-Korrekturen

- **Ziel:** Die Fehler aus dem Probebetrieb an der bestehenden Kalenderansicht sind behoben.
- **Begründung:** Tag, Detail und kurze Termine stören die tägliche Nutzung, unabhängig von der späteren Trennung.
- **Abnahme:** Die Tagesansicht zeigt nach jeder Navigation den heutigen Tag. Das Detail ist ohne Scrollen nach oben sichtbar. Termine von 30 Minuten sind lesbar. Eine Agenda-Ansicht ist vorhanden.
- **Status:** offen

### Neue Struktur

- **Ziel:** Kalenderseite, Leitstand, feste Kopfzeile und ein gemeinsamer Einstieg für Erfassen und Inbox.
- **Begründung:** Überblick und Raster brauchen verschiedene Flächen, vier Namen für einen Ablauf verwirren.
- **Abnahme:** `/kalender` nutzt die volle Fläche. Der Leitstand ist der Überblick mit Erfassungsfeld und der Dauer-Tab. Uhr und Datum stehen auf allen Seiten. Erfassen und Inbox sind ein Einstieg, das Datenmodell bleibt. Auf dem Handy bleibt der kombinierte Startbildschirm der mobile Leitstand, der Kalender ist ein Punkt in der unteren Navigation. Konzept und Prototypen sind nachgeführt.
- **Status:** offen

### Freie Zeit und Übergänge

- **Ziel:** Lücken und enge Übergänge lassen sich auf der Kalenderseite einblenden.
- **Begründung:** Die Information steckt in den vorhandenen Terminen und muss keine neue Ansicht werden.
- **Abnahme:** Die Schalter „Freie Zeit“ und „Übergänge“ stehen neben der Zeitraumwahl, Standard aus, Zustand pro Gerät gemerkt. `GapFinder` hat Tests für Überlappung, ganztägige Termine, Mitternacht und ausgeblendete Quellen. Nichts wird in einen fremden Kalender geschrieben.
- **Status:** offen

### Horizont

- **Ziel:** Eine optionale, verdichtende Ansicht sitzt im Leitstand.
- **Begründung:** Der Überblick soll nahe Zeit ausführlich und ferne Zeit knapp zeigen, ohne den Kalender zu ersetzen.
- **Abnahme:** „Horizont“ ist ein zusätzlicher Eintrag, nicht die Standardansicht. Der Aufbau folgt der Beschreibung in der Übergabe: heute, morgen, Rest der Woche, drei weitere Wochen, Zeilen aufklappbar. Farbe ist nie die einzige Information.
- **Status:** offen

### Abrufeinstellungen

- **Ziel:** In den Einstellungen und pro Kalenderquelle wählbar, wie oft abgerufen wird (z. B. 5, 15, 30, 60 Minuten). Der Cronjob bleibt minütlich und ist nur Taktgeber; die App entscheidet, welche Quellen fällig sind. Dazu ein Knopf „Jetzt aktualisieren“ im Leitstand und auf der Kalenderseite, der über den CronRunner sofort alle aktiven Quellen abruft und die gemeinsame Sperre gegen Doppelläufe nutzt. Auch der bestehende Einzelknopf „Jetzt abrufen“ auf der Seite Kalenderquellen nutzt die gemeinsame Sperre.
- **Begründung:** Häufige und seltene Kalender brauchen verschiedene Takte. Ein zweiter Lauf darf einen laufenden Abruf nicht überholen.
- **Bestand:** Pro Quelle gibt es `interval_min` (5 bis 1440 Minuten, Standard 15). Die Seite Kalenderquellen bietet 15, 30 und 60 Minuten. `refreshDue()` ruft nur fällige Quellen ab; der Auftrag `calendar.refresh` hängt am minütlichen Cron. `everyMinutes` am Auftrag wird nicht ausgewertet. „Jetzt abrufen“ gibt es nur je Quelle und geht an `CalendarService::refresh`, ohne CronRunner und ohne die Sperre. Ein globaler Takt und ein Knopf im Leitstand oder auf der Kalenderseite fehlen.
- **Abnahme:** Der Minuten-Cron bleibt der Taktgeber. Eine Quelle mit 60 Minuten wird nicht jede Minute geholt. „Jetzt aktualisieren“ auf Leitstand und Kalenderseite holt alle aktiven Quellen über den CronRunner. „Jetzt abrufen“ an einer einzelnen Quelle nutzt dieselbe Sperre. Läuft schon ein Abruf, startet kein zweiter.
- **Status:** offen

## Phase 4 – Feinschliff

### Gestaltung und Barrierefreiheit

- **Ziel:** Die Oberfläche wird filigraner, ohne die Mindestgrößen zu unterschreiten.
- **Begründung:** Die Richtung steht in `docs/prototyp/Feinschliff.dc.html`, die heutige Schriftgröße und die Touch-Ziele weichen davon und vom Konzept ab.
- **Abnahme:** Dunkle Tokens sind einmal geführt. Radien, Rahmen und Schriftgewichte kommen aus den Tokens. Keine Schrift unter 13 px, Touch-Ziele 44 px, `prefers-reduced-motion` überall, Quellen neben der Farbe erkennbar. Geprüft in Hell, Dunkel und bei 390 px Breite.
- **Status:** offen

### Architektur aufräumen

- **Ziel:** Schichten und Modulvertrag gelten auch im bestehenden Code.
- **Begründung:** Die Regeln bleiben. Heute liegen SQL und Navigation an mehreren Stellen außerhalb davon.
- **Abnahme:** Schnellzugriff und Medien haben Repositories. Items und Media bilden keinen Kreis. `Palette` liegt im Kern. Die Navigation kommt aus `navItems()`. Die Schichtenregel und der Modulvertrag werden nicht gelockert.
- **Status:** offen

## Später

### AP 9 – Projekte und Priorität

- **Ziel:** Vorhaben bündeln Aufgaben und Notizen, ohne ein Projektwerkzeug nachzubauen.
- **Begründung:** Priorität und Projekte stehen im Konzept, nicht im Code.
- **Abnahme:** Wie im Umsetzungskonzept bei AP 9 beschrieben, einschließlich der drei Prioritätsstufen an Einträgen.
- **Status:** offen

### Mini-Monat und 2-Wochen-Ansicht

- **Ziel:** Durch Monate blättern und die Zwei-Wochen-Ansicht den Platz nutzen lassen.
- **Begründung:** Beides braucht die Kalenderseite aus Phase 3 und soll deren Fläche nicht dauerhaft verengen.
- **Abnahme:** Die Monatsübersicht gibt es nur auf der Kalenderseite, sie ist ausblendbar und ersetzt keine zweite feste Spalte. Die Zwei-Wochen-Ansicht nutzt die Höhe anders als zwei Reihen der Vier-Wochen-Ansicht.
- **Status:** offen

### Spätere Optionen

- **Ziel:** Schreibender Kalenderzugriff und Jahresübersicht bleiben vorgemerkt.
- **Begründung:** Beides widerspricht einer geltenden Festlegung und braucht vor dem Bau eine eigene Entscheidung.
- **Abnahme:** Noch keine. Schreibender Zugriff erst nach einem neuen ADR mit Sicherheitsbewertung. Die Jahresübersicht erst, wenn der Speicherzeitraum über −30 bis +90 Tage hinaus erweitert ist.
- **Status:** offen
