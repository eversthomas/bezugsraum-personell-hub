# Deployment (All-Inkl oder anderer PHP-Webspace)

Diese Anleitung beschreibt, wie Personal Hub auf einen Webspace kommt. Sie ersetzt nicht AP 0 aus dem Umsetzungskonzept (Vorab-Checks), sondern setzt ihn voraus.

## Voraussetzungen

- PHP 8.3 (mindestens 8.2 prüfen, `composer.json` verlangt 8.3) mit `pdo_mysql`, `sodium`, `mbstring`, `curl`, `gd` oder `imagick`, `intl`, `openssl`; empfohlen `gmp` oder `bcmath` (Push-Verschlüsselung)
- MariaDB 10.6 oder neuer, eine leere Datenbank
- Subdomain mit HTTPS, deren Dokumentenstamm auf `public/` zeigt (nicht auf das Projektverzeichnis)
- Möglichkeit, einen Cronjob alle 15 Minuten einzurichten
- Upload-Limit mindestens 12 MB (`upload_max_filesize`, `post_max_size`), damit Handy-Fotos ankommen

## Erstinstallation

1. Lokal: `composer install --no-dev --optimize-autoloader`. Der Ordner `vendor/` wird mit hochgeladen, auf dem Server ist kein Composer nötig.
2. Projektverzeichnis hochladen (ohne `.git`, `tests/`, `config/config.php`, `config/keys/*`, `storage/*`). Der Dokumentenstamm der Subdomain ist `public/`.
3. `config/config.example.php` nach `config/config.php` kopieren und ausfüllen: Datenbank, `app.url` (mit `https://`), Pfad des Schlüssels, VAPID-Daten.
4. Auf dem Server oder über SSH (falls nicht möglich: lokal gegen die Server-Datenbank, sofern erreichbar):

   ```bash
   php bin/create-key.php      # legt config/keys/app.key an
   php bin/migrate.php         # Datenbank einrichten
   php bin/create-user.php <benutzername>   # Passwort eingeben, TOTP-Schlüssel notieren
   php bin/create-vapid.php    # Schlüssel für Push, in config/config.php eintragen
   ```

5. Verzeichnisse `storage/logs`, `storage/cache`, `storage/sessions`, `storage/uploads` müssen für PHP beschreibbar sein.
6. Cronjob alle 15 Minuten einrichten (Pfad zur PHP 8.3 des Webspace beachten):

   ```
   */15 * * * * /usr/bin/php8.3 /pfad/zum/projekt/bin/cron.php
   ```

7. Im Browser anmelden, Authenticator-Code eingeben, unter „Kalenderquellen“ die ICS-Links eintragen, unter „Benachrichtigungen“ das Gerät aktivieren.

## Prüfliste nach dem Einrichten

- `https://<subdomain>/` leitet zur Anmeldung, jeder andere Pfad ohne Anmeldung antwortet mit 404.
- `https://<subdomain>/config/config.php` und `https://<subdomain>/src/` antworten mit 404 (der Dokumentenstamm muss `public/` sein).
- `https://<subdomain>/robots.txt` enthält `Disallow: /`. Antwort-Header enthalten `X-Robots-Tag: noindex, nofollow, noarchive`.
- Anmeldung auf dem Handy und am Desktop klappt; „Gerät merken“ funktioniert.
- Kalenderquellen zeigen „Aktuell“ nach dem ersten Abruf; ein bewusst kaputter Link zeigt einen Fehler, die alten Termine bleiben.

## Aktualisieren

1. Neue Dateien hochladen (ohne `config/config.php`, `config/keys/`, `storage/`).
2. `php bin/migrate.php` ausführen. Migrationen laufen nur vorwärts.
3. Browser-Cache muss nicht geleert werden; der Service Worker prüft sich selbst.

## Sicherung

Gesichert werden müssen **alle drei** Dinge zusammen, sonst ist eine Wiederherstellung nicht möglich:

- Datenbank (Dump)
- `storage/uploads/` (Fotos)
- `config/keys/app.key` (ohne ihn sind verschlüsselte ICS-Links und TOTP-Geheimnisse unlesbar) und die VAPID-Schlüssel aus `config/config.php`

Das Backup verschlüsselt ablegen und die Wiederherstellung einmal an einer Testinstanz üben.

## Fehlersuche

- Ereignisse stehen in `storage/logs/app.log`. Dort stehen nie Titel, Notizen, Links oder Tokens, nur Fehlerarten und Orte.
- Leere weiße Seite oder „Interner Fehler“: `config/config.php` fehlt oder Datenbankzugang falsch.
- Kalenderquelle zeigt „Zugriff verweigert“: der ICS-Link wurde widerrufen oder neu erzeugt, unter „Link ersetzen“ neu eintragen.
- Push kommt nicht an: unter „Benachrichtigungen“ Testnachricht senden; ist das Gerät dort nicht aufgeführt, neu aktivieren. Auf dem iPhone muss die App vorher zum Home-Bildschirm hinzugefügt werden.
