# Deployment (All-Inkl oder anderer PHP-Webspace)

Die Anwendung kommt als Release-ZIP auf den Server und wird im Browser eingerichtet. Composer läuft dort nicht. Der Abschnitt „Ohne Installer“ beschreibt den Weg über die Kommandozeile, für den Entwicklungsrechner und für den Fall, dass ein Terminal vorhanden ist.

Voraussetzungen und Prüfliste gelten für beide Wege. Details stehen im [Umsetzungskonzept](umsetzungskonzept.md) und in [ADR 0009](adr/0009-installer-im-browser.md).

## Voraussetzungen

- PHP 8.5 mit `pdo_mysql`, `sodium`, `mbstring`, `curl`, `gd` oder `imagick`, `intl`, `openssl`. Empfohlen sind `gmp` oder `bcmath` für die Push-Verschlüsselung. Der Code läuft ab 8.3.
- MariaDB, eine leere Datenbank. Lokal entspricht das MySQL.
- Subdomain mit HTTPS. Der Dokumentenstamm zeigt auf `public/`, nicht auf das Projektverzeichnis. Der Name der Subdomain ist nicht erratbar.
- Cronjob, der minütlich eine HTTPS-Adresse aufrufen kann, mit HTTP-Benutzer und HTTP-Passwort.
- `storage/` für PHP beschreibbar, insbesondere `storage/logs`, `storage/cache`, `storage/sessions`, `storage/uploads`.
- Upload-Limit mindestens 12 MB (`upload_max_filesize`, `post_max_size`), damit Fotos vom Handy ankommen.

## Release erzeugen

Das ZIP entsteht auf GitHub, ohne Terminal.

1. In GitHub Desktop „View on GitHub“ öffnen. GitHub Desktop legt das Release nicht an.
2. Auf der Projektseite Releases öffnen und ein neues Release anlegen.
3. Den Tag neu vergeben, zum Beispiel `v0.1.0`, und das Release veröffentlichen.
4. Der Workflow hängt danach `personal-hub-v0.1.0.zip` und `personal-hub-v0.1.0.zip.sha256` an dieses Release. Er startet erst, wenn die Qualitätsprüfung für denselben Tag grün ist. Die Seite neu laden, sobald der Lauf fertig ist.

Im ZIP liegen `vendor/`, `LICENSE`, `THIRD-PARTY-NOTICES.md` und leere Verzeichnisse `storage/logs`, `storage/cache`, `storage/sessions` und `storage/uploads`. `LICENSE` liegt absichtlich dabei: sie nennt den Urheber, das gehört zur MIT-Lizenz. Nicht darin liegen `docs/`, `README.md`, `CHANGELOG.md`, `.git`, `.github`, `.cursor`, `tests` und die Werkzeuge für Tests und Analyse. `config/config.php` und Schlüsseldateien fehlen ebenfalls.

## Erstinstallation mit Installer

1. Das Release-ZIP aus dem GitHub-Release herunterladen. Es enthält `vendor/`. Es enthält keine `config/config.php`, keine Schlüssel und keinen Inhalt von `storage/`. Die Datei heißt `personal-hub-` plus der Tag, zum Beispiel `personal-hub-v0.1.0.zip`.
2. Das Archiv entpacken und hochladen. Der Dokumentenstamm der Subdomain ist `public/`. Eine Antwort „403 Forbidden“ direkt nach dem Hochladen bedeutet meist ein falsches Domainziel. Das Ziel muss auf `public/` zeigen, weil die `.htaccess` im Projektordner alles sperrt.
3. Die Subdomain im Browser öffnen. Der Installer schreibt einen Zufallscode nach `storage/install-code.txt`, außerhalb des Webroots.
4. Den Code im Dateimanager des KAS oder per FTP aus dieser Datei lesen und im Formular eingeben. Ohne den Code entsteht keine Installation.
5. Im Formular den Datenbankzugang eintragen und den Verbindungstest abwarten. Danach legt der Installer Konfiguration, Schlüssel, Tabellen, VAPID-Schlüssel und den ersten Benutzer an. Passwort mindestens 12 Zeichen. Den TOTP-QR-Code mit der Authenticator-App scannen oder das Geheimnis als Text abtippen.
6. Den Cronjob einrichten, wie unter „Cron im KAS“ beschrieben. Der Installer zeigt die Adresse später selbst an.
7. Der Installer legt `storage/install.lock` an und löscht `storage/install-code.txt` sowie `storage/install-attempts.txt`. Die Installer-Route antwortet danach mit 404, auch wenn die Sperrdatei später gelöscht wird, solange `config/config.php` existiert. `config/config.php` nie löschen, wenn die Einrichtung bleiben soll. Fehlt die Datei, beginnt die Einrichtung von vorn.
8. Anmelden, unter „Kalenderquellen“ die ICS-Links eintragen, unter „Benachrichtigungen“ das Gerät aktivieren.

## Prüfliste nach dem Einrichten

- Ohne Einrichtungscode lässt sich nichts installieren.
- Nach der Installation antwortet die Installer-Route mit 404, auch bei erneutem Aufruf und nach Löschen der Sperrdatei, solange `config/config.php` existiert.
- `https://<subdomain>/` leitet zur Anmeldung. Jeder andere Pfad ohne Anmeldung antwortet mit 404.
- `https://<subdomain>/config/config.php` und `https://<subdomain>/src/` antworten mit 404. Der Dokumentenstamm muss `public/` sein.
- `https://<subdomain>/robots.txt` enthält `Disallow: /`. Die Antwort enthält `X-Robots-Tag: noindex, nofollow, noarchive`, auch bei statischen Dateien.
- Ohne gültiges Token antwortet `/cron/{token}` mit 404. Mit gültigem Token und ohne HTTP-Zugang antwortet der Endpunkt mit 401 und `WWW-Authenticate`.
- Nach dem ersten Aufruf per HTTPS prüfen, ob das Sitzungscookie `ph_session` das Flag Secure trägt und der HSTS-Header gesendet wird. Fehlt beides, erkennt die App HTTPS nicht; dann `app.trust_proxy` auf true setzen und erneut prüfen. Apache sendet den HSTS-Header zusätzlich aus `public/.htaccess`. Fehlt nur das Secure-Flag, gilt derselbe Schritt.
- Anmeldung auf dem Handy und am Desktop klappt. „Gerät merken“ funktioniert.
- Kalenderquellen zeigen „Aktuell“ nach dem ersten Abruf. Ein bewusst kaputter Link zeigt einen Fehler, die alten Termine bleiben.

## Vor Ort prüfen

- Das Release-ZIP entpacken: keine `.env`, keine `.pem`, keine `.key`, keine Reste von `tests/`, keine `config.php`, und `docs/` fehlt. Die Größe ansehen.
- Den ersten Workflow-Lauf ansehen: Tag, Audit-Schritt, hochgeladenes ZIP samt SHA-256-Datei, Berechtigungen.
- Nach dem Einrichten `curl -I` auf eine statische Datei (Header) und den Cron-Aufruf mit Basic-Zugang prüfen.
- Einen Cron-Lauf provozieren und im Protokoll prüfen, ob zuerst 401 und danach 200 kommt.
- Nach der Einrichtung `config/config.php`, `config/keys/app.key` und `storage/cron-credentials.php` auf die Rechte 0600 prüfen.
- In `storage/sessions/` nach einigen Tagen prüfen, ob alte Dateien verschwinden.
- Nach dem ersten Aufruf prüfen, ob `Set-Cookie` das Flag `Secure` trägt und der HSTS-Header da ist. Sonst `app.trust_proxy` wie in der Prüfliste oben.
- `https://<domain>/config/config.php`, `/storage/` und `/config/keys/app.key` mit `curl` abrufen. Erwartet ist 404.
- `max_execution_time` gegen das Laufzeitbudget des Cron von etwa 50 Sekunden prüfen.
- Den Verbindungstest des Installers mit dem Datenbankserver des Hosters machen: Host, Port und die Fehlermeldung.

## Ohne Installer

Dieser Weg gilt auf dem Entwicklungsrechner und überall, wo eine Kommandozeile zur Verfügung steht.

1. Abhängigkeiten lokal installieren: `composer install --no-dev --optimize-autoloader`. Der Ordner `vendor/` wird mit hochgeladen.
2. Hochladen ohne `.git`, `docs/`, `README.md`, `CHANGELOG.md`, `tests/`, `config/config.php`, `config/keys/*` und ohne Inhalt von `storage/`. `LICENSE` und `THIRD-PARTY-NOTICES.md` bleiben im Wurzelverzeichnis. Dokumentenstamm ist `public/`.
3. `config/config.example.php` nach `config/config.php` kopieren und ausfüllen: Datenbank, `app.url` mit `https://`, Pfad des Schlüssels, VAPID-Daten. `app.trust_proxy` bleibt false, bis die Prüfliste etwas anderes zeigt.
4. Auf der Kommandozeile:

   ```bash
   php bin/create-key.php
   php bin/migrate.php
   php bin/create-user.php <benutzername>
   php bin/create-vapid.php
   ```

   `create-user.php` fragt das Passwort ab und gibt den TOTP-Schlüssel aus. Die VAPID-Schlüssel kommen in `config/config.php`.
5. `storage/logs`, `storage/cache`, `storage/sessions` und `storage/uploads` müssen für PHP beschreibbar sein.
6. Hintergrund: lokal `php bin/cron.php`. Auf dem Server den Abschnitt „Cron im KAS“ verwenden.

## Cron im KAS

Der Cronjob ist der Taktgeber und ruft jede Minute eine HTTPS-Adresse auf. Adresse, HTTP-Benutzer und HTTP-Passwort kommen aus:

```bash
php bin/create-cron-credentials.php
```

Der Befehl zeigt Adresse, Benutzer und Passwort einmal und schreibt nur die Hashes nach `storage/cron-credentials.php` (Dateirechte 0600). `config/config.php` bleibt unverändert. Ein neuer Aufruf macht den bisherigen Zugang sofort ungültig. Dasselbe kann nach der Anmeldung unter „Sicherheit“ der Knopf „Cron-Zugang neu erzeugen“. Im KAS: jede Minute, Protokoll HTTPS, die ausgegebene Adresse (`/cron/{token}`), HTTP-Benutzer und HTTP-Passwort. Das Token steht in der Adresse und damit in den Zugriffsprotokollen des Servers. Einen neuen Zugang gibt es unter „Sicherheit“. Das E-Mail-Feld bleibt leer, sonst kommt jede Minute eine Mail. Stehen Datenbankänderungen aus, antwortet der Cron mit einem kurzen Hinweis und führt nichts aus.

Ohne gültiges Token antwortet die Adresse mit 404. Mit gültigem Token und ohne oder mit falschem HTTP-Zugang antwortet sie mit 401. Mit beidem läuft derselbe Ablauf wie `php bin/cron.php`.

## Aktualisieren

1. Neue Dateien hochladen, ohne `config/config.php`, `config/keys/` und `storage/`.
2. Nach der Anmeldung zeigt die Anwendung nur noch „Datenbank aktualisieren“, solange Migrationen ausstehen. Der Hinweis erinnert an eine Sicherung. Der Knopf „Jetzt aktualisieren“ spielt die Änderungen ein. Abgemeldete Besucher sehen davon nichts und melden sich normal an. `php bin/migrate.php` bleibt der Weg auf der Kommandozeile. Migrationen laufen nur vorwärts.
3. Der Service Worker prüft sich selbst. Den Browser-Cache muss man dafür nicht leeren.

## Sicherung

Gesichert werden die Datenbank, `config/config.php` und `storage/` vollständig. In `storage/` liegen der Cron-Zugang (`storage/cron-credentials.php`), die Uploads und die übrigen Dateien. Der Schlüssel liegt in `config/keys/app.key` und gehört zur selben Sicherung. Die VAPID-Schlüssel stehen in `config/config.php`.

Ohne die Schlüsseldatei sind verschlüsselte ICS-Links und TOTP-Geheimnisse unlesbar. Das Backup verschlüsselt ablegen und die Wiederherstellung einmal an einer Testinstanz üben.

## Fehlersuche

- Ereignisse stehen in `storage/logs/app.log`. Dort stehen nie Titel, Notizen, Links oder Tokens, nur Fehlerarten und Orte.
- Leere Seite oder „Interner Fehler“: `config/config.php` fehlt oder der Datenbankzugang stimmt nicht. Fehlt die Datei absichtlich noch, ist der Installer zuständig.
- Kalenderquelle zeigt „Zugriff verweigert“: der ICS-Link wurde widerrufen oder neu erzeugt und wird unter „Link ersetzen“ neu eingetragen.
- Push kommt nicht an: unter „Benachrichtigungen“ eine Testnachricht senden. Fehlt das Gerät, neu aktivieren.
