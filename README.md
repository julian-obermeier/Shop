# Auftragsportal

Schlanke PHP/MySQL-Vermittlungsplattform für persönliche Angebote, Aufträge und Nachweise von Verkäuferinnen.

## Kernworkflow
1. Die Plattformverwaltung legt Verkäuferinnenkonten direkt an oder lädt Verkäuferinnen per einmaligem Link bzw. E-Mail ein.
2. Die Plattformverwaltung vermittelt ein persönliches Angebot an genau eine Verkäuferin.
3. Angebot enthält beliebig viele Positionen.
4. Jede Position definiert Vergütung, erfolgreiche Tage, Vorabkontrolle und getrennte tägliche Nachweisvorgänge.
5. Nachweisvorgänge können eigene Zeitfenster besitzen, z. B. Morgens 06:00–12:00, Mittags 13:00–16:00, Abends 18:00–22:00 oder Ganztags.
6. Verkäuferin akzeptiert die Regeln verbindlich.
7. Jede Position wird zu einem eigenen Auftrag.
8. Die Vergütung jedes Auftrags wird sofort in der Verkäuferinnen-Wallet vorgemerkt.
9. Verkäuferin lädt positionsbezogene Vorabfotos hoch.
10. Die Plattformprüfung gibt die Vorabkontrolle frei und legt das Startdatum fest.
11. Durchführungstage werden automatisch erzeugt.
12. Jeder Nachweisvorgang wird separat, der Reihe nach und nur innerhalb seines Zeitfensters mit genau einem Foto eingereicht.
13. Erst wenn alle Nachweisvorgänge eines Tages vorliegen, geht der Tag zur Plattformprüfung.
14. Verpasste Pflicht-Zeitfenster werden im Prüfcenter als unvollständig angezeigt.
15. Die Plattformprüfung entscheidet pro Tag `Erfüllt` oder `Nicht erfüllt`.
16. `Nicht erfüllt` erzeugt automatisch genau einen zusätzlichen Durchführungstag.
17. Nach dem letzten erfolgreichen Tag wechselt der Auftrag in die Versandphase.
18. Verkäuferin erhält die hinterlegte Versandadresse und bestätigt den Versand am Folgetag.
19. Erst danach ist der Auftrag vollständig abgeschlossen und die vorgemerkte Vergütung wird auszahlbar.
20. Verkäuferin hinterlegt PayPal oder Banküberweisung als Auszahlungsweg.
21. Die Plattformverwaltung dokumentiert die externe PayPal-/Bankzahlung und markiert auszahlbare Wallet-Buchungen als ausgezahlt.

## Wallet-Status
- **Vorgemerkt:** Auftrag angenommen, aber noch nicht vollständig abgeschlossen.
- **Auszahlbar:** Durchführung und Versand abgeschlossen.
- **Ausgezahlt:** Die Plattformverwaltung hat die externe PayPal-/Bankzahlung dokumentiert.

## Anforderungen
- PHP 8.2+
- MySQL 8 / MariaDB 10.5+
- PHP-Erweiterungen: PDO MySQL, fileinfo
- mod_rewrite bei Apache

## Installation
1. Repository in das Webroot klonen.
2. Neue, leere Datenbank anlegen.
3. `/install/` im Browser aufrufen.
4. App-URL, Datenbank und Zugang zur Plattformverwaltung eintragen.
5. Optional direkt die erste Verkäuferin anlegen.
6. Im Verwaltungsbereich unter **Versandadresse** die Zieladresse hinterlegen.

Bestehende Installationen werden beim ersten Aufruf nach einem `git pull` automatisch um neue Tabellen und Spalten erweitert.

`config/app.php` und hochgeladene Nachweise werden nicht versioniert.


## Verkäuferinnen-Einladungen
- Die Plattformverwaltung kann einen allgemeinen Einladungslink erstellen.
- Optional kann eine E-Mail-Adresse fest an die Einladung gebunden werden.
- Mit **Per E-Mail einladen** wird der Link direkt per PHP-Mail versendet.
- Einladungen sind standardmäßig 7 Tage gültig und nur einmal verwendbar.
- Verkäuferin legt Vorname, Nachname und Passwort selbst fest.
- Verwendete, abgelaufene oder widerrufene Links können nicht erneut genutzt werden.
- Falls der automatische Mailversand auf dem Hosting fehlschlägt, bleibt der Link sichtbar und kann manuell kopiert werden.

Optional kann in `config/app.php` ein Mail-Absender über `mail.from` und `mail.from_name` gesetzt werden.


## Vorlage „Angebot 1 · 14 Tage · 850 €“
Die Anwendung enthält eine direkt zuweisbare Admin-Vorlage unter **Angebote → Neues Angebot**.

Enthalten sind:
- Nylonstrumpfhose: 14 Tage, Tag und Nacht.
- Socken: 14 Tage, Tag und Nacht.
- Schuhe: 14 Tage, ganztägig einschließlich zu Hause.
- Slip: die letzten 4 Gesamttage, automatisch ans Angebotsende gekoppelt.
- Schweiß-Einlagen: die letzten 4 Gesamttage; am ersten dieser vier Tage duschen, danach kein Deo.
- Spucke: letzter Gesamttag.
- Fußnägel: letzter Gesamttag.
- Hornhaut: falls vorhanden am letzten Gesamttag, sonst dokumentierter Ersatznachweis.

Die drei 14-Tage-Hauptpositionen teilen sich einen gemeinsamen Start. Kürzere Endpositionen verschieben sich automatisch mit, wenn sich das späteste Ende einer Hauptposition durch Verlängerung nach hinten verschiebt. Bei einer Verschiebung bleiben Nachweise erhalten, deren Kalendertag weiterhin innerhalb des neuen Endfensters liegt.

Der Versand wird erst freigegeben, wenn alle Positionen abgeschlossen sind. Danach gibt es genau eine gemeinsame Versandbestätigung; erst anschließend wird die gesamte vorgemerkte Vergütung auszahlbar.
