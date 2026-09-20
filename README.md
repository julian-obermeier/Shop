# Shop V1 – Ankaufsplattform

Komplett neu aufgebaute, Composer-freie PHP-8/MySQL-Ankaufsplattform für ALL-INKL Shared Hosting.

## Enthaltene V1-Basis
- öffentlicher Angebotsbereich
- Verkäuferinnen-Registrierung ab 18
- E-Mail-Verifikation
- Login / Logout / Passwort-Reset
- Verkäuferinnen-Dashboard
- Angebotsannahme mit Auftragsnummer `YYYYNNNN`
- Aufträge und Vorabkontrolle
- geschützte Nachweisdateien
- Wallet mit vorgemerkten/verfügbaren Beträgen
- Admin-Login
- Admin-Dashboard
- Kategorienverwaltung
- Angebotsverwaltung
- Verkäuferinnen- und Auftragsübersicht
- Freigabe von Vorabkontrollen
- Systemeinstellungen
- Web-Installer
- PWA-Grundlage
- Cron-Einstiegspunkt
- umfangreiches Datenbankschema für den weiteren V1-Funktionsumfang

## Installation auf ALL-INKL

1. Repository in das Domain-Verzeichnis klonen oder aktualisieren.
2. PHP 8.2+ auswählen.
3. Schreibrechte für `config/`, `storage/private/` und `storage/logs/` sicherstellen.
4. MySQL/MariaDB-Datenbank im KAS anlegen.
5. `https://DEINE-DOMAIN/install/` aufrufen.
6. Datenbankdaten, Basis-URL, Mail-Absender und Admin-Zugang eintragen.
7. Installer abschließen.
8. Danach `install/` löschen oder per Dateirecht sperren.
9. Cronjob z. B. alle 5 Minuten:
   `php /www/htdocs/ACCOUNT/DOMAIN/cron.php`

## Update

```bash
cd /www/htdocs/w021867a/shop.fetisch-game.com
git pull origin main
php bin/update.php
```

Falls die Domain auf einem anderen Ordner liegt, nur den Pfad anpassen.

## Sicherheit
Private Uploads liegen unter `storage/private` und werden nicht direkt ausgeliefert. Dateiabrufe laufen über autorisierte PHP-Routen.

## Stand
V1-Neustart. Die alte Repository-Codebasis wurde vollständig ersetzt.
