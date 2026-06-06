# Codex-Auftrag: LAMP-Webapplikation für lokalen Kinder-Laufanlass mit Qualifikation und Finallauf

## 1. Ziel

Erstelle eine lokale Webapplikation auf Basis von LAMP für einen kleinen Laufanlass mit ca. 200 Einzelteilnehmern, primär Kindern.

Die Software ersetzt die bisherige Excel-Auswertung im Office. Der bestehende Papierprozess mit Laufzetteln bleibt grundsätzlich erhalten, wird aber durch eine strukturierte Erfassung, automatische Auswertung, Qualifikationswertung, Finallaufwertung und PDF-/Druckausgabe ergänzt.

## 2. Fachlicher Kontext

### 2.1 Ausgangslage

Aktueller Ablauf:

1. Ein Kind füllt einen Laufzettel mit persönlichen Angaben aus.
2. Das Kind kann innerhalb eines bestimmten Zeitfensters maximal zwei reguläre Läufe absolvieren.
3. Beide Zeiten werden auf dem Laufzettel notiert.
4. Nach Abschluss der regulären Läufe findet pro Wertungsgruppe ein Finallauf mit den drei schnellsten Kindern statt.
5. Die Laufzettel werden im Office ausgewertet.
6. Die Angaben und Zeiten werden bisher manuell in Excel übertragen.
7. Die Rangliste wird bisher in Excel erstellt.

### 2.2 Neuer Soll-Prozess

Die Software soll den Ablauf wie folgt unterstützen:

1. Ein Laufzettel wird mit eindeutiger Laufzettel-ID erzeugt.
2. Der Laufzettel enthält einen Bereich für persönliche Angaben und einen Bereich für Laufzeiten.
3. Beide Bereiche enthalten Laufzettel-ID, Name und Vorname als Redundanzkontrolle.
4. Personen können bereits vor Eintreffen der Zeiten erfasst werden.
5. Zeiten können später anhand der Laufzettel-ID ergänzt werden.
6. Pro Kind können maximal zwei reguläre Qualifikationsläufe erfasst werden.
7. Für die Qualifikation zählt die bessere gültige Zeit aus Lauf 1 und Lauf 2.
8. Die Wertung erfolgt getrennt nach Jahrgangsgruppe und Geschlecht.
9. Nach der Qualifikation werden pro Wertungsgruppe die drei schnellsten Kinder für den Finallauf vorgeschlagen.
10. Für Finalisten wird eine zusätzliche Finalzeit erfasst.
11. Die Endrangliste wird so gebildet:
    - Plätze 1 bis 3: Finalisten nach Finalzeit.
    - Ab Platz 4: Nicht-Finalisten nach bester Qualifikationszeit.
12. Die Ranglisten können im Browser angezeigt, gedruckt und als PDF exportiert werden.
13. Laufzettel können als PDF-Vorlage erzeugt werden.

## 3. Technische Zielplattform

### 3.1 Server

- Ubuntu Server 24.04 LTS oder neuer
- Apache 2
- MariaDB oder MySQL
- PHP 8.3 oder neuer
- Lokaler Betrieb im LAN
- Kein öffentlicher Internetbetrieb erforderlich
- Zugriff per Browser von einem oder mehreren lokalen Geräten

### 3.2 Architektur

Verwende einen schlanken PHP-MVC-Aufbau ohne grosses Framework.

Keine Laravel- oder Symfony-Abhängigkeit, ausser sie wird später ausdrücklich gewünscht.

Bevorzugter Aufbau:

```text
/var/www/sportlauf/
├── public/
│   ├── index.php
│   ├── assets/
│   │   ├── css/
│   │   └── js/
├── app/
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Views/
│   └── Helpers/
├── config/
│   └── database.php
├── database/
│   ├── schema.sql
│   └── seed.sql
├── storage/
│   ├── exports/
│   └── logs/
├── vendor/
├── composer.json
└── README.md
```

## 4. Ubuntu-LAMP-Installation

### 4.1 System aktualisieren

```bash
sudo apt update
sudo apt upgrade -y
```

### 4.2 Apache installieren

```bash
sudo apt install -y apache2
sudo systemctl enable apache2
sudo systemctl start apache2
sudo systemctl status apache2
```

Optional, falls UFW aktiv ist:

```bash
sudo ufw allow OpenSSH
sudo ufw allow "Apache"
sudo ufw enable
sudo ufw status
```

### 4.3 MariaDB installieren

MariaDB wird für dieses Projekt empfohlen, weil sie als MySQL-kompatible Datenbank für lokale LAMP-Anwendungen sehr gut geeignet ist.

```bash
sudo apt install -y mariadb-server mariadb-client
sudo systemctl enable mariadb
sudo systemctl start mariadb
sudo systemctl status mariadb
```

Absicherung ausführen:

```bash
sudo mysql_secure_installation
```

### 4.4 PHP installieren

```bash
sudo apt install -y php libapache2-mod-php php-cli php-mysql php-mbstring php-xml php-curl php-zip php-gd php-intl unzip
sudo systemctl restart apache2
php -v
```

### 4.5 Composer installieren

```bash
sudo apt install -y composer
composer --version
```

### 4.6 Projektverzeichnis vorbereiten

```bash
sudo mkdir -p /var/www/sportlauf
sudo chown -R $USER:www-data /var/www/sportlauf
sudo chmod -R 775 /var/www/sportlauf
```

### 4.7 Apache VirtualHost erstellen

Datei erstellen:

```bash
sudo nano /etc/apache2/sites-available/sportlauf.conf
```

Inhalt:

```apache
<VirtualHost *:80>
    ServerName sportlauf.local
    DocumentRoot /var/www/sportlauf/public

    <Directory /var/www/sportlauf/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/sportlauf_error.log
    CustomLog ${APACHE_LOG_DIR}/sportlauf_access.log combined
</VirtualHost>
```

Aktivieren:

```bash
sudo a2ensite sportlauf.conf
sudo a2enmod rewrite
sudo systemctl reload apache2
```

Optional Default-Site deaktivieren:

```bash
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

### 4.8 Datenbank und Benutzer erstellen

```bash
sudo mysql
```

SQL:

```sql
CREATE DATABASE sportlauf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'sportlauf_user'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

GRANT ALL PRIVILEGES ON sportlauf.* TO 'sportlauf_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 4.9 PHP-Testdatei

Nur zum Testen verwenden und danach löschen.

```bash
echo "<?php phpinfo();" > /var/www/sportlauf/public/info.php
```

Danach im Browser prüfen:

```text
http://SERVER-IP/info.php
```

Nach erfolgreichem Test löschen:

```bash
rm /var/www/sportlauf/public/info.php
```

## 5. Fachliche Anforderungen

## 5.1 Anlassverwaltung

Ein Anlass ist die oberste Einheit.

### Felder

- ID
- Anlassname
- Datum
- Streckenbezeichnung
- Zeitfenster optional
- Status
  - Vorbereitung
  - Aktiv
  - Abgeschlossen
  - Archiviert
- Bemerkung
- Erstellt am
- Geändert am

### Funktionen

- Anlass erstellen
- Anlass bearbeiten
- Anlass aktiv setzen
- Anlass abschliessen
- Anlass archivieren
- Optional: Anlass für nächstes Jahr kopieren

## 5.2 Jahrgangsgruppen / Kategorien

Die Kategorien werden über eine eigene Maske bearbeitet.

### Felder

- ID
- Anlass-ID
- Kategoriename
- Jahrgang von
- Jahrgang bis
- Sortierung
- Aktiv ja/nein

### Regeln

- Kategorien sind frei definierbar.
- Eine Kategorie besteht aus einem Jahrgangsbereich.
- Die Wertung erfolgt zusätzlich getrennt nach Geschlecht.
- Die Software muss prüfen, ob Jahrgangsbereiche überlappen.
- Die Software soll optional auf Lücken zwischen Jahrgangsbereichen hinweisen.
- Die Kategorie eines Teilnehmers wird automatisch anhand des Jahrgangs bestimmt.

### Beispiel

| Kategorie | Jahrgang von | Jahrgang bis |
|---|---:|---:|
| Kat. 1 | 2019 | 2020 |
| Kat. 2 | 2017 | 2018 |
| Kat. 3 | 2015 | 2016 |

Die effektiven Wertungsgruppen sind daraus:

- Kat. 1 Mädchen
- Kat. 1 Knaben
- Kat. 2 Mädchen
- Kat. 2 Knaben
- Kat. 3 Mädchen
- Kat. 3 Knaben

## 5.3 Teilnehmererfassung

Die Teilnehmererfassung muss getrennt von der Zeiterfassung möglich sein.

### Felder

- Laufzettel-ID
- Name
- Vorname
- Jahrgang
- Geschlecht
  - Mädchen
  - Knabe
- Klasse
- Ort
- Bemerkung

### Regeln

- Laufzettel-ID ist eindeutig pro Anlass.
- Name, Vorname, Jahrgang und Geschlecht sind Pflichtfelder.
- Klasse und Ort sind optionale Felder.
- Die Kategorie wird automatisch aus dem Jahrgang berechnet.
- Falls kein Jahrgangsbereich passt, muss der Teilnehmer als "ohne Kategorie" markiert werden.
- Dublettenwarnung bei gleicher Kombination aus Name, Vorname, Jahrgang und Geschlecht.

## 5.4 Qualifikations-Zeiterfassung

Zeiten können später ergänzt werden.

### Suche

Die Zeiterfassung muss Teilnehmer finden über:

- Laufzettel-ID
- Name
- Vorname
- Jahrgang
- Klasse
- Kategorie

### Felder

- Lauf 1
- Lauf 2
- Qualifikationsstatus
- Bemerkung

### Regeln

- Ein Kind darf maximal zwei reguläre Qualifikationsläufe haben.
- Ein gültiger Lauf reicht für die Qualifikationsrangliste.
- Wenn zwei Qualifikationszeiten vorhanden sind, zählt die bessere Zeit.
- Die Zeit wird per Stoppuhr gemessen.
- Genauigkeit: Zehntelsekunden.
- Intern werden Zeiten als Integer in Zehntelsekunden gespeichert.

### Akzeptierte Eingabeformate

Die Eingabe soll tolerant sein:

| Eingabe | Bedeutung | Ausgabe |
|---|---:|---|
| `1:23.4` | 1 Minute 23,4 Sekunden | `01:23.4` |
| `01:23.4` | 1 Minute 23,4 Sekunden | `01:23.4` |
| `1:23` | 1 Minute 23,0 Sekunden | `01:23.0` |
| `83.4` | 83,4 Sekunden | `01:23.4` |
| `83` | 83,0 Sekunden | `01:23.0` |

Ungültige Eingaben müssen klar angezeigt werden und dürfen nicht stillschweigend gespeichert werden.

## 5.5 Statuslogik

Teilnehmerstatus soll aus den Daten ableitbar sein.

### Mögliche Status

- Person erfasst, keine Zeit
- Qualifikationsfähig
- Finalist vorgeschlagen
- Finalist bestätigt
- Finalzeit erfasst
- Ohne Kategorie
- Ungültige Pflichtdaten
- Nicht gestartet
- Aufgegeben
- Disqualifiziert

### Regeln

Qualifikationsfähig ist ein Teilnehmer, wenn:

- Name vorhanden
- Vorname vorhanden
- Jahrgang vorhanden
- Geschlecht vorhanden
- Kategorie bestimmbar
- mindestens eine gültige Qualifikationszeit vorhanden

## 5.6 Finallauf-Logik

Nach den zwei regulären Läufen wird pro Wertungsgruppe ein Finallauf durchgeführt.

### Qualifikation

- Die Qualifikation basiert auf der besseren Zeit aus Lauf 1 und Lauf 2.
- Pro Wertungsgruppe, also Kategorie + Geschlecht, qualifizieren sich die drei schnellsten ranglistenfähigen Teilnehmer.
- Falls weniger als drei Teilnehmer in einer Wertungsgruppe vorhanden sind, qualifizieren sich alle ranglistenfähigen Teilnehmer dieser Gruppe.
- Bei Zeitgleichheit auf dem dritten Qualifikationsrang muss die Software eine Warnung anzeigen, weil dann organisatorisch entschieden werden muss, ob mehr als drei Kinder starten oder ob eine Regel zur Auflösung angewendet wird.

### Finale

Für Finalteilnehmer wird eine zusätzliche Finalzeit erfasst.

Regeln:

- Finalzeit wird ebenfalls in Zehntelsekunden gespeichert.
- Finalteilnehmer werden im Endklassement nach Finalzeit sortiert.
- Die Finalisten bilden die Plätze 1 bis 3 der Endrangliste.
- Nicht-Finalisten werden nach ihrer Qualifikationszeit ab Platz 4 rangiert.
- Wenn ein Finalteilnehmer im Finale keine gültige Zeit hat, muss der Status explizit gesetzt werden:
  - Finale nicht gestartet
  - Finale aufgegeben
  - Finale disqualifiziert
- Die Software darf eine fehlende Finalzeit nicht automatisch durch die Qualifikationszeit ersetzen.

### MVP-Regel bei Gleichstand auf Rang 3

- Bei Gleichstand auf dem dritten Qualifikationsrang wird eine Warnung angezeigt.
- Die automatische Finalistenliste markiert alle betroffenen Kinder als "Qualifikationsgleichstand prüfen".
- Standardmässig werden nur die ersten drei nach Zeit, Nachname, Vorname vorgeschlagen, aber nicht automatisch endgültig bestätigt.
- Die finale Auswahl muss manuell bestätigt werden können.

## 5.7 Rangliste

Die Rangliste ist die zentrale Auswertung.

### Gruppierung

Die Ausgabe erfolgt getrennt nach:

1. Kategorie
2. Geschlecht

### Sortierung Qualifikation

Innerhalb jeder Wertungsgruppe:

1. Beste Qualifikationszeit aufsteigend
2. Nachname
3. Vorname

### Sortierung Endrangliste mit Finale

1. Finalisten nach Finalzeit aufsteigend
2. Nicht-Finalisten nach bester Qualifikationszeit aufsteigend
3. Nachname
4. Vorname

### Ranglogik bei gleicher Zeit

Gleiche Zeiten erhalten den gleichen Rang. Der nächste Rang wird übersprungen.

Beispiel:

| Rang | Zeit |
|---:|---:|
| 1 | 01:10.0 |
| 2 | 01:12.3 |
| 2 | 01:12.3 |
| 4 | 01:15.8 |

### Felder in der Rangliste

- Rang
- Name
- Vorname
- Jahrgang
- Geschlecht
- Klasse
- Ort
- Kategorie
- Lauf 1
- Lauf 2
- Beste Qualifikationszeit
- Finalist ja/nein
- Finalzeit
- Wertungsstatus

### Ausgabe

- Qualifikationsrangliste
- Finalistenliste
- Endrangliste
- Browseransicht
- Druckansicht
- PDF-Export
- Optional CSV-Export

## 5.8 Laufzettel-PDF

Die Software muss Laufzettel als PDF erzeugen können.

### Layout

Empfohlen:

- Zwei Laufzettel pro A4-Seite
- Jeder Laufzettel mit eindeutiger Laufzettel-ID
- Personenteil
- Zeitenteil
- Beide Teile enthalten Laufzettel-ID, Name und Vorname

### Inhalt Personenteil

- Anlassname
- Datum
- Strecke
- Laufzettel-ID
- Name
- Vorname
- Jahrgang
- Geschlecht
- Klasse
- Ort

### Inhalt Zeitenteil

- Laufzettel-ID
- Name
- Vorname
- Jahrgang optional
- Klasse optional
- Lauf 1
- Lauf 2
- Hinweis: Es zählt die bessere der zwei Zeiten für die Qualifikation.
- Hinweis: Die drei schnellsten pro Wertungsgruppe qualifizieren sich für das Finale.

### Zusatzbereich Office

- Checkbox Person erfasst
- Checkbox Zeiten erfasst
- Checkbox Zuordnung geprüft
- Checkbox Finalist
- Bemerkung

## 6. Datenmodell

## 6.1 Tabelle `events`

```sql
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    distance_label VARCHAR(50) NOT NULL,
    time_window VARCHAR(100) NULL,
    status ENUM('preparation', 'active', 'closed', 'archived') NOT NULL DEFAULT 'preparation',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## 6.2 Tabelle `categories`

```sql
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    year_from INT NOT NULL,
    year_to INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_categories_event
        FOREIGN KEY (event_id)
        REFERENCES events(id)
        ON DELETE CASCADE,

    INDEX idx_categories_event_years (event_id, year_from, year_to)
);
```

## 6.3 Tabelle `participants`

```sql
CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category_id INT NULL,
    sheet_number VARCHAR(20) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    birth_year INT NOT NULL,
    gender ENUM('female', 'male') NOT NULL,
    school_class VARCHAR(50) NULL,
    city VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_participants_event
        FOREIGN KEY (event_id)
        REFERENCES events(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_participants_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE SET NULL,

    UNIQUE KEY uq_participants_event_sheet (event_id, sheet_number),
    INDEX idx_participants_name (event_id, last_name, first_name),
    INDEX idx_participants_category_gender (event_id, category_id, gender)
);
```

## 6.4 Tabelle `results`

```sql
CREATE TABLE results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    run1_time_tenths INT NULL,
    run2_time_tenths INT NULL,
    best_qualification_time_tenths INT NULL,

    is_finalist TINYINT(1) NOT NULL DEFAULT 0,
    finalist_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    final_time_tenths INT NULL,

    qualification_status ENUM('no_time', 'valid', 'dns', 'dnf', 'dsq') NOT NULL DEFAULT 'no_time',
    final_status ENUM('not_qualified', 'qualified', 'valid', 'dns', 'dnf', 'dsq') NOT NULL DEFAULT 'not_qualified',

    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_results_participant
        FOREIGN KEY (participant_id)
        REFERENCES participants(id)
        ON DELETE CASCADE,

    UNIQUE KEY uq_results_participant (participant_id),
    INDEX idx_results_qualification_time (best_qualification_time_tenths),
    INDEX idx_results_final_time (final_time_tenths),
    INDEX idx_results_finalist (is_finalist),
    INDEX idx_results_finalist_confirmed (finalist_confirmed)
);
```

## 6.5 Optional: Tabelle `users`

Für Version 1 kann ein einfacher lokaler Adminzugang reichen. Falls Login umgesetzt wird:

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'operator') NOT NULL DEFAULT 'operator',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## 7. Backend-Anforderungen

## 7.1 Services

Erstelle folgende Service-Klassen:

```text
app/Services/TimeParser.php
app/Services/CategoryResolver.php
app/Services/RankingService.php
app/Services/FinalistService.php
app/Services/PdfService.php
app/Services/SheetNumberService.php
```

### TimeParser

Aufgaben:

- Texteingabe in Zehntelsekunden umwandeln
- Zehntelsekunden in Anzeigeformat `MM:SS.Z` formatieren
- Ungültige Eingaben erkennen

### CategoryResolver

Aufgaben:

- Kategorie anhand Event-ID und Jahrgang bestimmen
- Fehlende Kategorie erkennen
- Überlappende Kategorien validieren

### RankingService

Aufgaben:

- Qualifikationsrangliste pro Kategorie und Geschlecht erzeugen
- Beste Qualifikationszeit aus Lauf 1 und Lauf 2 berücksichtigen
- Endrangliste mit Finalzeiten für Plätze 1 bis 3 und Qualifikationszeiten ab Platz 4 erzeugen
- Gleichstände korrekt behandeln

### FinalistService

Aufgaben:

- Die drei schnellsten Teilnehmer pro Wertungsgruppe als Finalisten vorschlagen
- Gleichstand auf dem dritten Qualifikationsrang erkennen
- Warnungen für Finalisten-Auswahl ausgeben
- Manuelle Bestätigung von Finalisten verwalten
- Finalstatus setzen

### PdfService

Aufgaben:

- Qualifikationsrangliste-PDF erzeugen
- Finalistenliste-PDF erzeugen
- Endranglisten-PDF erzeugen
- Laufzettel-PDF erzeugen

### SheetNumberService

Aufgaben:

- Laufzettel-ID generieren
- Pro Anlass eindeutige Nummer sicherstellen
- Format z. B. `001`, `002`, `003`

## 7.2 Controller

Erstelle folgende Controller:

```text
EventController
CategoryController
ParticipantController
ResultController
FinalistController
FinalResultController
RankingController
PdfController
ExportController
```

## 7.3 Routen

Beispielhafte Routen:

```text
GET  /                         Dashboard
GET  /events                   Anlassübersicht
GET  /events/create            Anlass erstellen
POST /events/store             Anlass speichern
GET  /events/{id}/edit         Anlass bearbeiten
POST /events/{id}/update       Anlass aktualisieren

GET  /categories               Jahrgangsgruppen
POST /categories/store         Jahrgangsgruppe speichern
POST /categories/{id}/update   Jahrgangsgruppe aktualisieren
POST /categories/{id}/delete   Jahrgangsgruppe deaktivieren/löschen

GET  /participants             Teilnehmerliste
GET  /participants/create      Person erfassen
POST /participants/store       Person speichern
GET  /participants/{id}/edit   Person bearbeiten
POST /participants/{id}/update Person aktualisieren

GET  /results                  Qualifikationszeiten erfassen
GET  /results/search           Teilnehmer suchen
GET  /results/{participant}/edit Zeiten bearbeiten
POST /results/{participant}/save Zeiten speichern

GET  /quick-entry              Schnellerfassung Person + Qualifikationszeiten
POST /quick-entry/save         Schnellerfassung speichern

GET  /rankings/qualification   Qualifikationsrangliste anzeigen
GET  /finalists                Finalistenliste anzeigen
POST /finalists/confirm        Finalisten bestätigen
GET  /final-results            Finalzeiten erfassen
POST /final-results/save       Finalzeiten speichern
GET  /rankings                 Endrangliste anzeigen
GET  /rankings/print           Druckansicht Endrangliste
GET  /rankings/pdf             Endrangliste PDF

GET  /sheets/pdf               Laufzettel PDF erzeugen
GET  /export/csv               CSV Export
```

## 8. UI-Anforderungen

## 8.1 Allgemein

- Saubere, schlichte Oberfläche
- Optimiert für lokale Bedienung im Office
- Gute Tastaturbedienung
- Pflichtfelder klar markieren
- Fehlermeldungen direkt beim Feld anzeigen
- Speichern-und-nächster-Zettel-Funktion

## 8.2 Hauptnavigation

- Dashboard
- Anlass
- Jahrgangsgruppen
- Teilnehmer erfassen
- Qualifikationszeiten erfassen
- Schnellerfassung
- Qualifikationsrangliste
- Finalisten
- Finalzeiten erfassen
- Endrangliste
- Laufzettel-PDF
- Export/Backup
- Einstellungen

## 8.3 Dashboard

Dashboard zeigt:

- Aktiver Anlass
- Anzahl erfasster Personen
- Anzahl mit gültiger Qualifikationszeit
- Anzahl ohne Zeit
- Anzahl ohne Kategorie
- Anzahl möglicher Dubletten
- Anzahl vorgeschlagener Finalisten
- Anzahl bestätigter Finalisten
- Anzahl Finalisten ohne Finalzeit
- Link zur Qualifikationsrangliste
- Link zur Finalistenliste
- Link zur Finalzeiterfassung
- Link zur Endrangliste

## 8.4 Maske Jahrgangsgruppen

Funktionen:

- Tabelle bestehender Gruppen
- Neue Gruppe hinzufügen
- Gruppe bearbeiten
- Gruppe aktiv/inaktiv setzen
- Validierung auf Überlappungen
- Vorschau der Wertungsgruppen Mädchen/Knaben

## 8.5 Maske Teilnehmer erfassen

Felder:

- Laufzettel-ID
- Name
- Vorname
- Jahrgang
- Geschlecht
- Klasse
- Ort
- Bemerkung

Funktionen:

- Automatische Kategorieanzeige
- Dublettenwarnung
- Speichern und nächster Zettel
- Nach dem Speichern Cursor wieder auf Name oder Laufzettel-ID

## 8.6 Maske Qualifikationszeiten erfassen

Funktionen:

- Suche nach Laufzettel-ID
- Suche nach Name/Vorname
- Anzeige der gefundenen Person
- Gegenprüfung Name/Vorname gegen Zeitenzettel
- Eingabe Lauf 1 und Lauf 2
- Automatische Anzeige der besten Qualifikationszeit
- Speichern und nächster Zettel

## 8.7 Schnellerfassung

Eine kombinierte Maske für fertige Laufzettel:

- Personendaten
- Laufzeit 1
- Laufzeit 2
- Automatische Kategorie
- Automatische beste Qualifikationszeit
- Speichern und nächster Zettel

## 8.8 Qualifikationsrangliste

Filter:

- Alle Kategorien
- Eine Kategorie
- Mädchen
- Knaben

Funktionen:

- Anzeige der Qualifikationsrangliste
- Markierung der Top 3 pro Wertungsgruppe
- Warnung bei Gleichstand auf Rang 3
- Link zur Finalistenbestätigung

## 8.9 Finalistenliste

Funktionen:

- Anzeige der drei schnellsten Teilnehmer pro Wertungsgruppe
- Basis ist die beste Zeit aus Lauf 1 und Lauf 2
- Warnung bei Gleichstand auf dem dritten Qualifikationsrang
- Möglichkeit, Finalisten manuell zu bestätigen
- Druckansicht für die Finalistenliste
- Optional PDF für Finalistenliste

## 8.10 Finalzeiten erfassen

Funktionen:

- Anzeige nur bestätigter Finalisten
- Gruppierung nach Kategorie und Geschlecht
- Eingabe der Finalzeit in Zehntelsekunden
- Status pro Finalist:
  - gültig
  - nicht gestartet
  - aufgegeben
  - disqualifiziert
- Automatische Vorschau der Endrangliste

## 8.11 Endrangliste

Filter:

- Alle Kategorien
- Eine Kategorie
- Mädchen
- Knaben

Ausgaben:

- Browseransicht
- Druckansicht
- PDF
- CSV

## 9. PDF-Anforderungen

## 9.1 PDF-Bibliothek

Verwende bevorzugt Dompdf oder TCPDF.

Empfehlung für einfache HTML-basierte PDF-Ausgaben:

```bash
composer require dompdf/dompdf
```

## 9.2 Ranglisten-PDF

Anforderungen:

- Qualifikationsrangliste optional separat ausgeben
- Finalistenliste optional separat ausgeben
- Endrangliste mit Finalwertung ausgeben
- Anlassname, Datum und Strecke im Kopfbereich
- Gruppierung nach Kategorie und Geschlecht
- Jede Wertungsgruppe klar überschrieben
- Optional Seitenumbruch pro Wertungsgruppe
- Tabellenkopf auf jeder Seite wiederholen
- Fusszeile mit Erstellungsdatum

## 9.3 Laufzettel-PDF

Anforderungen:

- Zwei Laufzettel pro A4-Seite
- Eindeutige Laufzettel-ID
- Nummernbereich wählbar, z. B. 001 bis 250
- Personenteil und Zeitenteil
- Redundante Angaben auf beiden Teilen
- Druckfreundliches Schwarz-Weiss-Layout

## 10. CSV-/Backup-Anforderungen

Erstelle CSV-Export für:

- Teilnehmer
- Qualifikationsresultate
- Finalresultate
- Qualifikationsrangliste
- Endrangliste

CSV soll UTF-8 sein und mit Semikolon getrennt werden, damit es in deutschsprachigen Excel-Installationen gut importierbar ist.

Beispiel:

```text
Rang;Name;Vorname;Jahrgang;Geschlecht;Klasse;Ort;Kategorie;Lauf 1;Lauf 2;Beste Qualifikation;Finalist;Finalzeit;Wertungsstatus
```

## 11. Validierungen

## 11.1 Teilnehmer

- Laufzettel-ID darf pro Anlass nur einmal vorkommen.
- Name darf nicht leer sein.
- Vorname darf nicht leer sein.
- Jahrgang muss vierstellig sein.
- Geschlecht muss `female` oder `male` sein.
- Kategorie muss bestimmbar sein, sonst Warnung.

## 11.2 Kategorien

- Jahrgang von darf nicht grösser sein als Jahrgang bis.
- Innerhalb eines Anlasses dürfen sich aktive Kategorien nicht überlappen.
- Kategorien sollen sortierbar sein.

## 11.3 Zeiten

- Zeit muss in gültiges Zehntelsekundenformat umwandelbar sein.
- Negative Zeiten sind ungültig.
- Unrealistisch kleine Zeiten optional warnen.
- Unrealistisch grosse Zeiten optional warnen.
- Beste Qualifikationszeit wird serverseitig berechnet, nicht clientseitig vertraut.
- Finalzeit wird separat erfasst und darf nicht automatisch aus Qualifikationszeiten übernommen werden.

## 11.4 Finale

- Nur qualifikationsfähige Teilnehmer können Finalisten werden.
- Finalisten müssen pro Wertungsgruppe vorgeschlagen werden.
- Finalisten müssen manuell bestätigt werden können.
- Gleichstand auf Rang 3 muss als Warnung sichtbar sein.
- Endrangliste darf erst als vollständig gelten, wenn alle bestätigten Finalisten eine Finalzeit oder einen Finalstatus haben.

## 12. Sicherheit

Da lokaler Betrieb vorgesehen ist, genügt für Version 1 ein einfacher Schutz.

Mindestanforderungen:

- Datenbankzugangsdaten nicht im Webroot speichern
- SQL nur über Prepared Statements
- Eingaben serverseitig validieren
- HTML-Ausgaben escapen
- Schreibrechte nur für `storage/`
- Optional einfacher Login für Admin/Operator

## 13. Akzeptanzkriterien MVP

Der MVP gilt als erfüllt, wenn:

1. LAMP auf Ubuntu Server installiert ist.
2. Die Anwendung über Apache lokal erreichbar ist.
3. Ein Anlass erstellt werden kann.
4. Jahrgangsgruppen editiert werden können.
5. Überlappende Jahrgangsgruppen erkannt werden.
6. Teilnehmer mit Laufzettel-ID erfasst werden können.
7. Kategorie wird automatisch anhand Jahrgang bestimmt.
8. Teilnehmer können ohne Zeiten gespeichert werden.
9. Qualifikationszeiten können später ergänzt werden.
10. Zwei Qualifikationszeiten pro Teilnehmer werden unterstützt.
11. Die bessere Qualifikationszeit wird automatisch berechnet.
12. Ein Teilnehmer mit nur einer gültigen Qualifikationszeit wird gewertet.
13. Qualifikationsrangliste wird getrennt nach Kategorie und Geschlecht erzeugt.
14. Pro Wertungsgruppe werden die drei schnellsten Teilnehmer als Finalisten vorgeschlagen.
15. Gleichstand auf Rang 3 der Qualifikation wird erkannt und als Warnung angezeigt.
16. Finalisten können manuell bestätigt werden.
17. Finalzeiten können separat erfasst werden.
18. Endrangliste berücksichtigt Finalzeiten für die Finalisten und Qualifikationszeiten für Nicht-Finalisten.
19. Gleiche Zeiten erhalten gleiche Ränge.
20. Rangliste kann gedruckt werden.
21. Rangliste kann als PDF exportiert werden.
22. Laufzettel können als PDF erzeugt werden.
23. Laufzettel enthalten Personenteil und Zeitenteil.
24. Beide Laufzettelbereiche enthalten Laufzettel-ID, Name und Vorname.
25. CSV-Export der Rangliste funktioniert.

## 14. Empfohlene Implementierungsreihenfolge

## Phase 1: Basis

1. Projektstruktur erstellen
2. Apache VirtualHost konfigurieren
3. Datenbankverbindung implementieren
4. Datenbankschema erstellen
5. Basislayout und Navigation erstellen

## Phase 2: Stammdaten

1. Anlassverwaltung
2. Jahrgangsgruppenverwaltung
3. CategoryResolver
4. Validierung überlappender Jahrgangsgruppen

## Phase 3: Teilnehmer und Qualifikationszeiten

1. Teilnehmererfassung
2. SheetNumberService
3. Dublettenwarnung
4. TimeParser
5. Qualifikationszeiterfassung
6. Schnellerfassung

## Phase 4: Qualifikation und Finale

1. Beste Qualifikationszeit berechnen
2. Qualifikationsrangliste erzeugen
3. Finalisten pro Wertungsgruppe bestimmen
4. Gleichstand auf Qualifikationsrang 3 erkennen
5. Finalistenbestätigung implementieren
6. Finalzeiten-Erfassung implementieren
7. Endrangliste mit Finalwertung erzeugen
8. Druckansicht

## Phase 5: PDF und Export

1. Dompdf/TCPDF einbinden
2. Qualifikationsranglisten-PDF
3. Finalistenlisten-PDF
4. Endranglisten-PDF
5. Laufzettel-PDF
6. CSV-Export
7. Backup-Hinweise im README

## 15. Testfälle

## 15.1 Zeitparser

| Eingabe | Erwartet |
|---|---:|
| `1:23.4` | 834 |
| `01:23.4` | 834 |
| `1:23` | 830 |
| `83.4` | 834 |
| `83` | 830 |
| `abc` | Fehler |
| `-1:00.0` | Fehler |

## 15.2 Beste Qualifikationszeit

| Lauf 1 | Lauf 2 | Beste Qualifikationszeit |
|---:|---:|---:|
| 834 | 812 | 812 |
| 834 | NULL | 834 |
| NULL | 812 | 812 |
| NULL | NULL | NULL |

## 15.3 Finalistenbestimmung

Input pro Wertungsgruppe:

| Teilnehmer | Beste Qualifikationszeit |
|---|---:|
| A | 700 |
| B | 720 |
| C | 740 |
| D | 750 |

Erwartung:

| Teilnehmer | Finalist |
|---|---|
| A | ja |
| B | ja |
| C | ja |
| D | nein |

## 15.4 Gleichstand auf Rang 3

Input pro Wertungsgruppe:

| Teilnehmer | Beste Qualifikationszeit |
|---|---:|
| A | 700 |
| B | 720 |
| C | 740 |
| D | 740 |

Erwartung:

- A und B sind unkritische Finalisten.
- C und D erzeugen eine Warnung wegen Gleichstand auf Rang 3.
- Die Finalisten müssen manuell bestätigt werden.

## 15.5 Endrangliste mit Finale

Input:

| Teilnehmer | Qualifikation | Finalzeit | Finalist |
|---|---:|---:|---|
| A | 700 | 730 | ja |
| B | 720 | 710 | ja |
| C | 740 | 720 | ja |
| D | 750 | NULL | nein |

Erwartung:

| Teilnehmer | Rang |
|---|---:|
| B | 1 |
| C | 2 |
| A | 3 |
| D | 4 |

## 15.6 Ranglogik

Input:

| Teilnehmer | Zeit |
|---|---:|
| A | 700 |
| B | 720 |
| C | 720 |
| D | 750 |

Erwartung:

| Teilnehmer | Rang |
|---|---:|
| A | 1 |
| B | 2 |
| C | 2 |
| D | 4 |

## 15.7 Kategoriezuordnung

Kategorie:

| Name | Von | Bis |
|---|---:|---:|
| Kat. 1 | 2019 | 2020 |
| Kat. 2 | 2017 | 2018 |

Tests:

| Jahrgang | Erwartet |
|---:|---|
| 2020 | Kat. 1 |
| 2019 | Kat. 1 |
| 2018 | Kat. 2 |
| 2016 | Keine Kategorie |

## 16. Hinweise für Codex

- Implementiere keine Dummyfunktionen, die fachliche Logik nur vortäuschen.
- Die Kernlogik muss serverseitig korrekt umgesetzt werden.
- Verwende Prepared Statements.
- Halte Services testbar und möglichst unabhängig von Controller-Code.
- Speichere Zeiten intern immer als Zehntelsekunden.
- Berechne `best_qualification_time_tenths` immer serverseitig aus `run1_time_tenths` und `run2_time_tenths`.
- Bestimme Finalisten immer auf Basis der Qualifikationsrangliste pro Kategorie + Geschlecht.
- Ersetze fehlende Finalzeiten nie automatisch durch Qualifikationszeiten.
- Laufzettel-ID ist keine Startnummer, sondern eine interne Zuordnungsnummer.
- Die Qualifikationsrangliste basiert immer auf Kategorie + Geschlecht + bester Qualifikationszeit.
- Die Endrangliste basiert für Finalisten auf Finalzeit und für Nicht-Finalisten auf Qualifikationszeit.
- Die PDF-Ausgaben müssen druckbar und schlicht sein.
- Die Anwendung ist für lokalen Betrieb optimiert, nicht für öffentliche Online-Anmeldung.

## 17. README-Anforderungen

Erstelle eine `README.md` mit:

1. Zweck der Anwendung
2. Systemvoraussetzungen
3. LAMP-Installation Kurzfassung
4. Datenbankeinrichtung
5. Apache-VirtualHost
6. Projektinstallation
7. Konfiguration
8. Backup/Restore
9. Bedienablauf am Anlass inklusive Qualifikation und Finale
10. Troubleshooting
