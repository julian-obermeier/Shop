# Auftragsportal

Komplett neue, schlanke PHP/MySQL-Anwendung für Direktangebote zwischen Admin/Käufer und Verkäuferin.

## Kernworkflow
1. Admin legt Verkäuferinnenkonto an.
2. Admin erstellt ein Angebot für genau eine Verkäuferin.
3. Angebot enthält beliebig viele Positionen.
4. Jede Position definiert Vergütung, erfolgreiche Tage, Vorabkontrolle und getrennte tägliche Nachweisvorgänge.
5. Nachweisvorgänge können eigene Zeitfenster besitzen, z. B. Morgens 06:00–12:00, Mittags 13:00–16:00, Abends 18:00–22:00 oder Ganztags.
6. Verkäuferin akzeptiert die Regeln verbindlich.
7. Jede Position wird zu einem eigenen Auftrag.
8. Die Vergütung jedes Auftrags wird sofort in der Verkäuferinnen-Wallet vorgemerkt.
9. Verkäuferin lädt positionsbezogene Vorabfotos hoch.
10. Admin gibt die Vorabkontrolle frei und legt das Startdatum fest.
11. Durchführungstage werden automatisch erzeugt.
12. Jeder Nachweisvorgang wird separat, der Reihe nach und nur innerhalb seines Zeitfensters mit genau einem Foto eingereicht.
13. Erst wenn alle Nachweisvorgänge eines Tages vorliegen, geht der Tag zur Admin-Prüfung.
14. Verpasste Pflicht-Zeitfenster werden im Prüfcenter als unvollständig angezeigt.
15. Admin entscheidet pro Tag `Erfüllt` oder `Nicht erfüllt`.
16. `Nicht erfüllt` erzeugt automatisch genau einen zusätzlichen Durchführungstag.
17. Nach dem letzten erfolgreichen Tag wechselt der Auftrag in die Versandphase.
18. Verkäuferin erhält die hinterlegte Versandadresse und bestätigt den Versand am Folgetag.
19. Erst danach ist der Auftrag vollständig abgeschlossen und die vorgemerkte Vergütung wird auszahlbar.
20. Verkäuferin hinterlegt PayPal oder Banküberweisung als Auszahlungsweg.
21. Admin führt die Zahlung extern aus und markiert die auszahlbaren Wallet-Buchungen als ausgezahlt.

## Wallet-Status
- **Vorgemerkt:** Auftrag angenommen, aber noch nicht vollständig abgeschlossen.
- **Auszahlbar:** Durchführung und Versand abgeschlossen.
- **Ausgezahlt:** Admin hat die externe PayPal-/Bankzahlung dokumentiert.

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
6. Im Admin unter **Versandadresse** die Zieladresse hinterlegen.

Bestehende Installationen werden beim ersten Aufruf nach einem `git pull` automatisch um neue Tabellen und Spalten erweitert.

`config/app.php` und hochgeladene Nachweise werden nicht versioniert.
