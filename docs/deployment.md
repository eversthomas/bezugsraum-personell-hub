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

## Erstinstallation mit Installer

1. Das Release-ZIP aus dem GitHub-Release herunterladen. Es enthält `vendor/`. Es enthält keine `config/config.php`, keine Schlüssel und keinen Inhalt von `storage/`.
2. Das Archiv entpacken und hochladen. Der Dokumentenstamm der Subdomain ist `public/`.
3. Die Subdomain im Browser öffnen. Der Installer schreibt einen Zufallscode nach `storage/install-code.txt`, außerhalb des Webroots.
4. Den Code im Dateimanager des KAS oder per FTP aus dieser Datei lesen und im Formular eingeben. Ohne den Code entsteht keine Installation.
5. Im Formular den Datenbankzugang eintragen und den Verbindungstest abwarten. Danach legt der Installer Konfiguration, Schlüssel, Tabellen, VAPID-Schlüssel und den ersten Benutzer an. Passwort mindestens 12 Zeichen. Den TOTP-QR-Code mit der Authenticator-App scannen oder das Geheimnis als Text abtippen.
6. Die angezeigte Cron-Adresse samt Token in den Cronjob des KAS übernehmen: jede Minute, HTTPS, HTTP-Benutzer und HTTP-Passwort setzen. Das E-Mail-Feld leer lassen, sonst kommt jede Minute eine Mail. Den Pfad der Adresse legt das Paket Härtung fest. Bis dahin zeigt der Installer die dann geltende Adresse.
7. Der Installer legt eine Sperrdatei an und löscht `storage/install-code.txt`. Die Installer-Route antwortet danach mit 404, auch wenn die Sperrdatei später gelöscht wird, solange `config/config.php` existiert.
8. Anmelden, unter „Kalenderquellen“ die ICS-Links eintragen, unter „Benachrichtigungen“ das Gerät aktivieren.

## Prüfliste nach dem Einrichten

- Ohne Einrichtungscode lässt sich nichts installieren.
- Nach der Installation antwortet die Installer-Route mit 404, auch bei erneutem Aufruf und nach Löschen der Sperrdatei, solange `config/config.php` existiert.
- `https://<subdomain>/` leitet zur Anmeldung. Jeder andere Pfad ohne Anmeldung antwortet mit 404.
- `https://<subdomain>/config/config.php` und `https://<subdomain>/src/` antworten mit 404. Der Dokumentenstamm muss `public/` sein.
- `https://<subdomain>/robots.txt` enthält `Disallow: /`. Die Antwort enthält `X-Robots-Tag: noindex, nofollow, noarchive`, auch bei statischen Dateien.
- Der Cron-Endpunkt antwortet ohne HTTP-Zugang oder ohne Token mit 404.
- Anmeldung auf dem Handy und am Desktop klappt. „Gerät merken“ funktioniert.
- Kalenderquellen zeigen „Aktuell“ nach dem ersten Abruf. Ein bewusst kaputter Link zeigt einen Fehler, die alten Termine bleiben.

## Ohne Installer

Dieser Weg gilt auf dem Entwicklungsrechner und überall, wo eine Kommandozeile zur Verfügung steht.

1. Abhängigkeiten lokal installieren: `composer install --no-dev --optimize-autoloader`. Der Ordner `vendor/` wird mit hochgeladen.
2. Hochladen ohne `.git`, `tests/`, `config/config.php`, `config/keys/*` und ohne Inhalt von `storage/`. Dokumentenstamm ist `public/`.
3. `config/config.example.php` nach `config/config.php` kopieren und ausfüllen: Datenbank, `app.url` mit `https://`, Pfad des Schlüssels, VAPID-Daten.
4. Auf der Kommandozeile:

   ```bash
   php bin/create-key.php
   php bin/migrate.php
   php bin/create-user.php <benutzername>
   php bin/create-vapid.php
   ```

   `create-user.php` fragt das Passwort ab und gibt den TOTP-Schlüssel aus. Die VAPID-Schlüssel kommen in `config/config.php`.
5. `storage/logs`, `storage/cache`, `storage/sessions` und `storage/uploads` müssen für PHP beschreibbar sein.
6. Hintergrund: lokal `php bin/cron.php`. Auf dem Server, solange der HTTPS-Endpunkt noch nicht steht, ersatzweise dasselbe Skript über den Cron des Hostings. Sobald der Endpunkt existiert, gilt der Minuten-Aufruf per HTTPS aus der Erstinstallation.

## Aktualisieren

1. Neue Dateien hochladen, ohne `config/config.php`, `config/keys/` und `storage/`.
2. `php bin/migrate.php` ausführen, sobald eine Kommandozeile erreichbar ist. Migrationen laufen nur vorwärts. Ein späterer Weg ohne Terminal wird mit dem Installer nicht vermischt und ist noch nicht festgelegt.
3. Der Service Worker prüft sich selbst. Den Browser-Cache muss man dafür nicht leeren.

## Sicherung

Gesichert werden müssen alle drei Dinge zusammen, sonst ist eine Wiederherstellung nicht möglich:

- Datenbank (Dump)
- `storage/uploads/` (Fotos)
- `config/keys/app.key` und die VAPID-Schlüssel aus `config/config.php`

Ohne die Schlüsseldatei sind verschlüsselte ICS-Links und TOTP-Geheimnisse unlesbar. Das Backup verschlüsselt ablegen und die Wiederherstellung einmal an einer Testinstanz üben.

## Fehlersuche

- Ereignisse stehen in `storage/logs/app.log`. Dort stehen nie Titel, Notizen, Links oder Tokens, nur Fehlerarten und Orte.
- Leere Seite oder „Interner Fehler“: `config/config.php` fehlt oder der Datenbankzugang stimmt nicht. Fehlt die Datei absichtlich noch, ist der Installer zuständig.
- Kalenderquelle zeigt „Zugriff verweigert“: der ICS-Link wurde widerrufen oder neu erzeugt und wird unter „Link ersetzen“ neu eingetragen.
- Push kommt nicht an: unter „Benachrichtigungen“ eine Testnachricht senden. Fehlt das Gerät, neu aktivieren.
