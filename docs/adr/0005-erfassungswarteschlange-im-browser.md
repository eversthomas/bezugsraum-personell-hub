# ADR 0005: Offline-Warteschlange im Seitenskript statt im Service Worker

## Kontext

Das Umsetzungskonzept nennt eine Offline-Warteschlange für neue Einträge. Jede ändernde Anfrage braucht das CSRF-Token der Sitzung. Ein Service Worker kennt es nicht, und authentifizierte Seiten dürfen nicht zwischengespeichert werden.

## Entscheidung

Die Warteschlange liegt in `localStorage` und wird vom Seitenskript `capture.js` gefüllt und geleert. Jeder Eintrag trägt eine `client_uuid`; der Server speichert dieselbe Kennung nur einmal (Unique-Schlüssel). Gesendet wird beim Laden einer angemeldeten Seite, beim Ereignis „online“ und jede Minute. Der Service Worker liefert offline nur die statische Seite `/offline.html`, die Einträge vormerkt. Fotos liegen als verkleinerte Data-URL in der Warteschlange.

## Folgen

Ein Eintrag wird erst gesendet, wenn eine angemeldete Seite geöffnet ist. Ein Hintergrundabgleich bei geschlossener App ist damit nicht möglich. `localStorage` fasst etwa 5 MB; bei vollem Speicher meldet die Oberfläche das, statt Einträge zu verlieren. Eine Umstellung auf IndexedDB wäre später ohne Änderung am Server möglich.
