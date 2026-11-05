# ADR 0008: Trennung von Kalender und Leitstand

## Kontext

Der Leitstand ist heute die Kalenderansicht. Mit mehreren Quellen wird das Raster eng, und Erfassen liegt auf derselben Seite wie das Wochenraster.

## Entscheidung

Der Kalender wird eine eigene Seite mit voller Fläche (Tag, Woche, 2 Wochen, 4 Wochen). Der Leitstand wird der Überblick und der Dauer-Tab: Uhr, nächster Termin, heutiger Tag, Aufgaben, Erfassen. Die Kalenderseite öffnet man bei Bedarf. Auf dem Handy bleibt der Startbildschirm kombiniert (Erfassen und heutiger Tag) und gilt als mobiler Leitstand. Der Kalender ist dort ein eigener Punkt in der unteren Navigation. „Horizont“ ist ein Kandidat für den Leitstand, keine Pflichtansicht.

## Folgen

Erfassen liegt auf dem Leitstand, nicht auf der Kalenderseite. Vorgemerkte Blöcke erscheinen auf der Kalenderseite. Die Standardansicht Tag gilt für den Kalender und wird pro Gerät gemerkt.
