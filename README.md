# Auftragsportal

Komplett neue, schlanke PHP/MySQL-Anwendung für Direktangebote zwischen Admin/Käufer und Verkäuferin.

## Kernworkflow
1. Admin legt Verkäuferinnenkonto an.
2. Admin erstellt ein Angebot für genau eine Verkäuferin.
3. Angebot enthält beliebig viele Positionen.
4. Verkäuferin akzeptiert die Regeln verbindlich.
5. Jede Position wird zu einem eigenen Auftrag.
6. Verkäuferin lädt positionsbezogene Vorabfotos hoch.
7. Admin gibt die Vorabkontrolle frei.
8. Durchführungstage werden automatisch erzeugt.
9. Pro Durchführungstag entstehen getrennte Nachweisvorgänge. Bei 3 Vorgängen: `Morgens`, `Mittags`, `Abends`.
10. Jeder Nachweisvorgang wird separat und der Reihe nach mit genau einem Foto eingereicht.
11. Erst wenn alle Nachweisvorgänge des Tages vorliegen, geht der gesamte Tag an den Admin zur Prüfung.
12. Admin entscheidet für den Tag `Erfüllt` oder `Nicht erfüllt`.
13. `Nicht erfüllt` erzeugt automatisch genau einen zusätzlichen Durchführungstag mit denselben Nachweisvorgängen.

## Anforderungen
- PHP 8.2+
- MySQL 8 / MariaDB 10.5+
- PHP-Erweiterungen: PDO MySQL, fileinfo
- mod_rewrite bei Apache

## Installation
1. Repository in das Webroot klonen.
2. Neue, leere Datenbank anlegen.
3. `/install/` im Browser aufrufen.
4. App-URL, Datenbank und Admin-Zugang eintragen.
5. Optional direkt die erste Verkäuferin anlegen.

`config/app.php` und hochgeladene Nachweise werden nicht versioniert.
