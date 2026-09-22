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


## Duftproben / Duftbewertungen
- Die Plattformverwaltung kann unabhängig von Angeboten jederzeit eine Duftprobe bei einer Verkäuferin anfragen.
- Die Anfrage enthält eine frei benennbare Probe bzw. einen Gegenstand und optional eine genauere Frage.
- Die Verkäuferin muss jede offene Anfrage mit einem Wert von **1 bis 10** beantworten.
- 1 steht standardmäßig für sehr geringe und 10 für sehr starke Duftintensität.
- Ein optionaler Kommentar kann zusammen mit der Bewertung übermittelt werden.
- Antworten werden mit Zeitstempel gespeichert und in der Admin-Historie angezeigt.
- Offene Duftproben können vor der Antwort widerrufen werden.
- Offene Anfragen erscheinen direkt im Verkäuferinnen-Dashboard.


## Großupdate · Operations Center
Das Portal enthält jetzt zusätzlich eine operative Arbeitszentrale für die tägliche Bearbeitung.

### Benachrichtigungen & Fristen
- Eigene Mitteilungszentrale für Plattformverwaltung und Verkäuferinnen.
- Hinweise bei neuen/aktualisierten/zurückgezogenen Angeboten, Annahmen, Vorabkontrollen, Tagesprüfungen, Nachforderungen, Nachrichten, Duftproben, Versand und Auszahlungen.
- In-App-Erinnerungen für überfällige Nachforderungen, offene Duftproben, fehlende Vorabfotos, bevorstehende Nachweisfenster und überfälligen Versand.
- Wiederkehrende Erinnerungen werden höchstens einmal pro Kalendertag erzeugt.

### Verkäuferinnen-Akte
- Zentrale Detailseite je Verkäuferin mit Angeboten, Aufträgen, Wallet, Auszahlungsverlauf, Duftproben und offenen Nachforderungen.
- Schnellaktionen für neues Angebot, Duftprobe und Verkäuferinnen-Vorschau.

### Admin-Korrekturen
- Startdatum eines laufenden Auftrags mit Pflichtbegründung korrigieren.
- Zusätzlichen Pflichttag manuell anhängen bzw. einen noch unberührten manuell hinzugefügten Tag wieder entfernen.
- Tagesentscheidung zurücksetzen, sofern der Folgeprozess dies noch sicher zulässt.
- Jede Korrektur wird im Activity-Log protokolliert und relevante Änderungen werden der Verkäuferin mitgeteilt.

### Nachweise & Fotos
- Große Admin-Fotoansicht mit Vor/Zurück, Metadaten und geschütztem Download.
- Einzelne Vorab- oder Tagesfotos können gezielt verworfen und neu angefordert werden, ohne andere gültige Fotos zu löschen.
- Gezielt neu angeforderte Tagesfotos bleiben bis zur erneuten Einreichung freigeschaltet, auch wenn das ursprüngliche Zeitfenster bereits abgelaufen ist.

### Nachrichten
- Nachrichtenverlauf pro Auftrag zwischen Verkäuferin und Plattform.
- Verkäuferinnen sehen ausschließlich die Plattform als Gegenstelle, keine Admin-Identität.
- Neue Nachrichten erzeugen Benachrichtigungen.

### Wallet & Auszahlungen
- Admin kann einzelne auszahlbare Wallet-Buchungen auswählen statt immer den gesamten verfügbaren Betrag zu markieren.
- Jede Auszahlung wird als Batch mit Datum, Methode, Referenz, Notiz und enthaltenen Buchungen dokumentiert.
- Für jede Auszahlung steht ein PDF-Beleg zur Verfügung.
- PayPal-/Bankdaten werden verschlüsselt gespeichert und in der Admin-Ansicht standardmäßig maskiert.
- Der lokale Verschlüsselungsschlüssel liegt unter `storage/private/.app-key` und ist nicht Bestandteil des Git-Repositories. Bei einem Serverumzug muss diese Datei zusammen mit der Datenbank gesichert und übernommen werden.

### Admin-Dashboard „Heute“
- Heutige Nachweise und Zeitfenster.
- Prüfbereite Vorabkontrollen und Durchführungstage.
- Verpasste Nachweise und offene Nachreichungen.
- Offene Duftproben.
- Versand heute bzw. überfällig – erst wenn das gesamte Angebot tatsächlich versandbereit ist.
- Ungelesene Nachrichten.
- Auszahlbare Beträge je Verkäuferin.


## Reminder Engine / Cronjob
Zeitabhängige Erinnerungen werden nicht mehr erst beim Öffnen einer Portal-Seite berechnet. Dafür gibt es einen eigenständigen Cron-Runner.

### Einrichtung
1. Nach dem Deployment als Plattformverwaltung **Heute → Reminder Engine** öffnen.
2. Dort wird automatisch eine geschützte Cron-URL erzeugt.
3. Diese URL bei ALL-INKL als Cronjob hinterlegen.
4. Empfohlenes Intervall: **alle 10 Minuten**.
5. Anschließend auf der Reminder-Engine-Seite prüfen, ob **Letzter Erfolg** aktuell ist.

Der HTTP-Cron wird durch einen zufälligen 64-stelligen Token geschützt. Dieser liegt ausschließlich unter:
`storage/private/.cron-token`

Alternativ kann `cron.php` über PHP-CLI ausgeführt werden; bei einem CLI-Aufruf ist kein Token erforderlich.

Die Engine prüft unter anderem:
- Nachweisfenster, die innerhalb der nächsten Stunde beginnen,
- tatsächlich verpasste Nachweisfenster,
- seit 24 Stunden offene Nachforderungen,
- fehlende Vorabfotos,
- seit 24 Stunden offene Duftproben,
- überfälligen Versand, sobald das gesamte Angebot versandbereit ist.

Parallelstarts werden über eine lokale Lock-Datei verhindert. Im Admin-Bereich werden letzter Start, letzter erfolgreicher Lauf, Status, Fehler und die zuletzt erzeugte Anzahl an Erinnerungen angezeigt.

## Verkäuferinnen-Dashboard als Aufgaben-App
Die Startseite der Verkäuferin priorisiert jetzt nach Handlungsbedarf:
- **Jetzt erledigen** als dominante Hauptkarte, wenn ein Nachweisfenster gerade offen ist.
- Live-Countdown bis zum Ende des aktuell offenen Fensters.
- Wenn gerade nichts offen ist: Live-Countdown bis zum nächsten Nachweisfenster.
- Freigegebene Nachreichungen erscheinen sofort als **Jetzt erledigen**, ohne künstliches altes Zeitlimit.
- **Heute** als Timeline mit erledigten, aktuell offenen, späteren und verpassten Nachweisvorgängen.
- **Morgen** mit den bereits geplanten Nachweisvorgängen des Folgetages.
- Nachweise ohne unmittelbaren Handlungsbedarf, Duftproben, Versand und weitere Schritte bleiben darunter unter **Als Nächstes** sichtbar.

Beim Erreichen eines Countdown-Zeitpunkts aktualisiert sich die Dashboard-Seite automatisch, damit der Status von „später“ auf „jetzt fällig“ beziehungsweise anschließend auf „verpasst“ wechselt.
