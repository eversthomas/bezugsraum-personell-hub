# ADR 0004: Eigener schlanker HTTP-Client für Web Push

## Kontext

`minishlink/web-push` sendet über einen PSR-18-Client mit PSR-17-Fabriken und sucht sie zur Laufzeit mit `php-http/discovery`. Im Projekt ist keiner installiert. Die Regeldatei verlangt eine Rückfrage vor neuen Composer-Paketen, und eine Rückfrage war bei der Umsetzung nicht möglich.

## Entscheidung

Unter `src/Notify/Http/` liegt eine kleine eigene Umsetzung von PSR-7 (Anfrage, Antwort, Stream, URI), PSR-17 (Fabrik) und PSR-18 (cURL-Client). Der Client erlaubt nur https, folgt keinen Weiterleitungen, hat kurze Zeitlimits und begrenzt die Antwortgröße. `MinishlinkPushSender` bekommt ihn ausdrücklich übergeben, die automatische Suche wird nicht benutzt.

## Folgen

Keine neue Abhängigkeit, aber rund 400 Zeilen eigener Code, die nur das Nötigste abdecken. Wer lieber Guzzle oder `symfony/http-client` einsetzt, ersetzt das Konstruktor-Argument `$client` in `MinishlinkPushSender`; der Rest bleibt unverändert. Push-Endpunkte werden zusätzlich auf bekannte Dienste beschränkt, weil der Server sie aufruft (SSRF-Schutz).
