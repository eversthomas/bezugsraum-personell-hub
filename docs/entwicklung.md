# Entwicklung

## Testdatenbank

Die Datenbanktests lesen den Zugang aus `phpunit.xml`. PHPUnit verwendet diese Datei, sobald sie liegt, und ignoriert dann `phpunit.xml.dist`. `phpunit.xml` bleibt lokal und steht in `.gitignore`.

1. Die Datenbank anlegen:

```bash
newdb personal_hub_test
```

Der Befehl legt die Datenbank an und gibt Host, Port, Datenbankname, Benutzer und Passwort aus. Der Benutzer gilt für `localhost`.

2. Die Vorlage kopieren und den Beispielblock aktivieren:

```bash
cp phpunit.xml.dist phpunit.xml
```

3. Die fünf ausgegebenen Werte in die Variablen `PH_TEST_DB_HOST`, `PH_TEST_DB_PORT`, `PH_TEST_DB_NAME`, `PH_TEST_DB_USER` und `PH_TEST_DB_PASSWORD` eintragen.

`PH_TEST_DB_HOST=localhost` ist nötig, wenn der Benutzer für `localhost` angelegt wurde. Danach reicht `composer test`, die Zugangsdaten müssen nicht mehr in die Shell getippt werden.

Ist die Datenbank nicht erreichbar, werden die Datenbanktests übersprungen.
