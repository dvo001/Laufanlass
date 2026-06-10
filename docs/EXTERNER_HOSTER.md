# Betrieb bei einem externen Hoster

Diese Anleitung ist fuer klassische PHP/MySQL-Hoster, bei denen Dateien per FTP,
SFTP oder Hosting-Dateimanager hochgeladen werden.

## Voraussetzungen

- PHP 8.1 oder neuer
- MySQL oder MariaDB
- PDO MySQL PHP-Erweiterung
- Apache mit `.htaccess` und `mod_rewrite`
- Optional: Composer, wenn echte PDF-Dateien mit Dompdf erzeugt werden sollen

Ohne Dompdf zeigt die App druckbare HTML-Seiten an. Das reicht fuer Laufblaetter
und Ranglisten meistens aus.

## Upload-Paket erstellen

Lokal kann ein ZIP-Paket fuer den Hoster erstellt werden:

```bash
scripts/build_hosting_package.sh
```

Das Paket liegt danach unter:

```text
storage/exports/sportlauf-hosting.zip
```

Dieses ZIP kann beim Hoster entpackt werden. Eine vorhandene
`config/database.php` wird absichtlich nicht in das Paket aufgenommen.

## Empfohlene Variante: Webroot auf `public/`

Wenn der Hoster erlaubt, den DocumentRoot der Domain zu setzen:

1. Alle Projektdateien auf den Server laden, z.B. nach:
   `/home/kunde/sportlauf`
2. DocumentRoot der Domain auf diesen Ordner setzen:
   `/home/kunde/sportlauf/public`
3. Datenbank im Hosting-Panel erstellen.
4. `database/schema.sql` in phpMyAdmin oder Adminer importieren.
5. `config/database.hosting.php` nach `config/database.php` kopieren.
6. In `config/database.php` die Datenbankwerte des Hosters eintragen.
7. Domain im Browser aufrufen.

## Alternative: Hoster zeigt auf den Projektordner

Wenn der Hoster den DocumentRoot nicht auf `public/` setzen kann:

1. Alle Projektdateien in den Webordner des Hosters laden, z.B. `public_html`.
2. Die Datei `.htaccess` im Projektroot muss mit hochgeladen werden.
3. Die App ist danach direkt unter der Domain erreichbar.

Die Root-`.htaccess` leitet intern nach `public/index.php` weiter und sperrt
private Ordner wie `app`, `config`, `database`, `storage`, `tests` und `vendor`.

## Datenbank einrichten

Im Hosting-Panel:

1. MySQL/MariaDB-Datenbank erstellen.
2. Benutzer und Passwort notieren.
3. `database/schema.sql` importieren.
4. Optional `database/seed.sql` importieren, wenn Beispieldaten gewuenscht sind.

Dann:

```bash
cp config/database.hosting.php config/database.php
```

In `config/database.php` eintragen:

```php
return [
    'host' => 'HOSTNAME_DES_HOSTERS',
    'database' => 'DATENBANKNAME',
    'username' => 'DATENBANKUSER',
    'password' => 'DATENBANKPASSWORT',
    'charset' => 'utf8mb4',
];
```

Viele Hoster verwenden nicht `localhost`, sondern z.B. `mysql.examplehost.ch`.

## Laufblaetter drucken

Im Browser aufrufen:

```text
/sheets/pdf?from=1&to=20
```

Beim Drucken:

- Papier: A4
- Ausrichtung: Querformat
- Skalierung: 100 Prozent oder Tatsaechliche Groesse
- Zwei Laufblaetter pro Seite

## Echte PDF-Ausgabe mit Dompdf

Wenn Composer auf dem Hoster verfuegbar ist:

```bash
composer install --no-dev --optimize-autoloader
composer require dompdf/dompdf
```

Wenn Composer nicht verfuegbar ist, kann `vendor/` lokal erzeugt und mit
hochgeladen werden. Die App funktioniert aber auch ohne Dompdf als Druckansicht.

## Sicherheitscheck

Nach dem Upload pruefen:

- `/config/database.php` darf nicht im Browser sichtbar sein.
- `/database/schema.sql` darf nicht im Browser sichtbar sein.
- `/app/Services/TimeParser.php` darf nicht im Browser sichtbar sein.
- `/sheets/pdf?from=1&to=2` soll die Laufblaetter anzeigen.

Wenn private Dateien sichtbar sind, muss der Hoster den DocumentRoot auf
`public/` setzen oder `.htaccess`/`mod_rewrite` aktivieren.
