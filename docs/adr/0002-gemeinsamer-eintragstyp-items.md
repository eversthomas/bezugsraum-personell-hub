# ADR 0002: Gemeinsamer Eintragstyp „items“

## Kontext

Erfassen kommt vor dem Sortieren. Ein Gedanke startet in der Inbox und wird danach zur Aufgabe oder zur Notiz. Getrennte Tabellen würden beim Einsortieren Kopieren und Sonderfälle erzeugen.

## Entscheidung

Inbox, Aufgaben und Notizen teilen sich die Tabelle `items`. Das Feld `type` ist `inbox`, `task` oder `note`. Einsortieren ändert den Typ, statt einen neuen Datensatz anzulegen.

## Folgen

Ein Erfassungsweg, ein Medienbezug, eine Fälligkeit. Wiederholung und Kontext hängen am selben Eintrag. Kontext ist eine Einordnung und keine Zugriffsgrenze.
