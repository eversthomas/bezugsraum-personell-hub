# ADR 0001: Eigenes PHP ohne Framework

## Kontext

Die Oberfläche ist der Kern von Personal Hub. Dafür braucht es schlanke JSON-Endpunkte und wenig eigenen Kleber. Ein Framework oder CMS würde mehr Struktur mitbringen, als der Umfang hergibt.

## Entscheidung

Personal Hub ist ein eigenes PHP-Projekt ohne Framework, ohne ORM und ohne SPA. Der Datenbankzugriff läuft über PDO mit Prepared Statements. Die Oberfläche ist Server-HTML plus Vanilla JS, ohne Build-Schritt. Wenige Pakete decken ICS, Markdown, Web Push und TOTP ab.

## Folgen

Router, Container, Templates und Sicherheitsheader entstehen im Projekt. Der Code bleibt überschaubar und ohne Plugin-System. Kommt später Bedarf an einem ORM, einem Event-Bus oder einem SPA-Framework auf, wird das in einem neuen ADR begründet.
