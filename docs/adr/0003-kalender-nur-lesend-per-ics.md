# ADR 0003: Kalender nur lesend per ICS

## Kontext

Der Leitstand soll Termine aus mehreren Konten in einer Ansicht zeigen. Schreibzugriff auf fremde Kalender gehört nicht zum MVP und würde die Quellen verändern.

## Entscheidung

Kalenderquellen sind ICS-Links und werden nur gelesen. `sabre/vobject` löst Wiederholungen auf. Termine liegen als Einzeltermine im Cache. Schlägt ein Abruf fehl, bleibt der bisherige Stand stehen. Einen Termin im Ursprungskonto anzulegen bleibt einem späteren Arbeitspaket vorbehalten und läuft über vorbereitete Links.

## Folgen

Im MVP gibt es keinen CalDAV-Schreibzugriff und kein Drag-and-drop. Die Ansicht rechnet Serien nicht selbst. Weitere Quellarten können später über `CalendarProviderInterface` dazukommen.
