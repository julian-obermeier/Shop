# MASTERPROMPT – Wear&Earn / Reverse-Shop-Ankaufsplattform

**Status:** verbindliche fachliche und technische Projektspezifikation  
**Repository:** `julian-obermeier/Shop`  
**Zeitzone:** `Europe/Berlin`  
**Stand:** 18.09.2026

---

## 1. Verbindlichkeit und Priorität

Dieses Dokument ist ab sofort die maßgebliche Spezifikation für die weitere Entwicklung der Plattform.

Bei Widersprüchen gilt folgende Reihenfolge:

1. `MASTERPROMPT.md`
2. ausdrücklich später vom Auftraggeber bestätigte Änderungen
3. bestehender Anwendungscode
4. `README.md`
5. ältere Annahmen, Zwischenstände oder frühere Implementierungsentscheidungen

Bestehende Funktionen, Datenmodelle oder Oberflächen, die diesem Dokument widersprechen, müssen angepasst, entfernt oder ersetzt werden.

Die Anwendung ist **kein klassischer Shop**, sondern eine **Reverse-Shop-/Ankaufsplattform**. Der Betreiber erstellt Ankaufangebote. Volljährige Anbieterinnen können Angebote auswählen, konkrete Aufträge anfragen, die vereinbarten Leistungen erbringen, Nachweise einreichen, Ware versenden und nach Prüfung eine Vergütung erhalten.

---

## 2. Technische Basis

Die bestehende technische Basis bleibt:

- PHP 8.3+
- Laravel 13
- MySQL / MariaDB
- Blade
- Vanilla CSS / JavaScript
- private Dateispeicherung für Nachweise und sensible Dateien
- serverseitige Validierung und Preisberechnung
- responsive Weboberfläche
- Datenbank-Queue und Datenbank-Cache dürfen genutzt werden
- kein Docker-Zwang
- kein Node-/Vite-Zwang für die produktive Grundoberfläche

Alle zeitbezogenen Regeln verwenden ausschließlich `Europe/Berlin`. Gerätezeit, Browserzeit oder frei wählbare Benutzerzeitzonen dürfen die fachlichen Fristen nicht bestimmen.

---

## 3. Rollen und Konten

### 3.1 Anbieterin

Eine Anbieterin ist ein registrierter volljähriger Benutzer der Plattform.

### 3.2 Administration

Es gibt fachlich **genau ein Admin-Konto** mit Vollzugriff.

Nicht vorgesehen sind:

- mehrere Administratoren
- Staff-Rollen
- Accounting-Rollen
- abgestufte Adminrechte
- frei konfigurierbare Rollen
- ein internes Berechtigungssystem für mehrere Mitarbeiter

Bestehende Rollen-/Permission-Strukturen dürfen nicht als fachlich notwendige Funktion behandelt werden.

### 3.3 Admin-Login

Für das Admin-Konto gilt:

- Login mit E-Mail/Benutzername und Passwort
- **keine Zwei-Faktor-Authentifizierung**
- keine verpflichtende E-Mail-OTP-Challenge
- normale technische Rate-Limits gegen automatisierte Angriffe sind erlaubt
- keine feste Kontosperre nach einer bestimmten Anzahl Fehlversuche

---

## 4. Registrierung, Alter, E-Mail und Profil

### 4.1 Volljährigkeit

Die Plattform ist ausschließlich für volljährige Anbieterinnen vorgesehen.

Bei der Registrierung:

- Geburtsdatum ist Pflicht
- Registrierung unter 18 Jahren wird technisch verhindert
- zusätzliche Pflichtbestätigung: „Ich bin mindestens 18 Jahre alt“
- keine Ausweisprüfung
- kein Upload von Personalausweis/Reisepass
- keine externe Alters-/Identitätsprüfung
- keine separate Identitätsverifikation als Voraussetzung für Aufträge oder Auszahlungen

### 4.2 E-Mail-Verifikation

Nach der Registrierung darf sich die Anbieterin anmelden und ihr Profil vervollständigen.

Vor Auswahl, Anfrage oder Start eines Auftrags muss die E-Mail-Adresse verifiziert sein.

Bei Änderung der E-Mail-Adresse muss die neue Adresse erneut verifiziert werden.

### 4.3 Passwort

Die Anbieterin kann:

- ihr Passwort im eingeloggten Bereich ändern
- über „Passwort vergessen“ einen E-Mail-basierten Reset durchführen

### 4.4 Profildaten

Die Anbieterin darf selbst ausschließlich ändern:

- E-Mail-Adresse
- Telefonnummer

Andere Stammdaten dürfen nur vom Admin geändert werden, insbesondere:

- Vorname
- Nachname
- Geburtsdatum
- Anschrift
- sonstige Stammdaten

Auszahlungsdaten werden als eigener Bereich behandelt und folgen den separaten Auszahlungsregeln.

### 4.5 Kontolöschung

Eine Anbieterin kann ihr Konto **nicht selbst löschen**.

Nur der Admin kann Konten deaktivieren oder löschen.

---

## 5. Kontodeaktivierung

Bei Deaktivierung:

- Login wird gesperrt
- keine neuen Aufträge
- bestehende Daten bleiben erhalten
- bestehende Wallet-/Auszahlungsvorgänge werden nicht automatisch gelöscht
- bestehende Aufträge werden nicht automatisch beendet

Für jeden laufenden Auftrag muss der Admin beim Deaktivieren entscheiden:

- weiterlaufen
- abbrechen
- pausieren

Diese Entscheidung wird protokolliert.

**Konsistenzregel:** Da ein deaktiviertes Konto keinen Login erlaubt, kann „weiterlaufen“ nur für Auftragsphasen verwendet werden, die keine weitere Interaktion der Anbieterin benötigen, z. B. bereits erfolgter Versand, Wareneingang, Warenprüfung oder Vergütungsfreigabe. Befindet sich der Auftrag noch in einer Phase mit aktiven Nachweis- oder Eingabepflichten der Anbieterin, muss der Admin pausieren oder abbrechen.

### 5.1 Pause

Bei einem pausierten Trageauftrag:

- die aktuelle Trageserie endet
- bisherige Nachweise bleiben archiviert
- bisherige Tragetage zählen nach Wiederaufnahme nicht mehr
- nach Wiederaufnahme beginnt die Serie erneut bei Tag 1

Ein pausierter Socken-Trageauftrag blockiert weiterhin den einzigen Socken-Trageplatz.

---

## 6. Angebote

Der Admin erstellt und verwaltet alle Ankaufangebote.

Ein Angebot kann enthalten:

- Titel
- Beschreibung
- Kategorie
- Grundvergütung
- Regeln
- Trage-/Nutzungsdauer
- Zusatzoptionen
- Nachweisanforderungen
- Nachweiszeitfenster
- Versandvorgaben
- Tracking-Vorgaben
- Prüfregeln
- KO-Kriterien
- Qualitäts-/Punktebänder
- Gesichtspflicht für bestimmte Nachweise
- Kapazitätslimit gleichzeitig offener/aktiver Aufträge
- angebotsspezifische Auswahl-/Eingabefelder
- eine vorherige Kontroll-/Bestätigungsstufe, soweit für das konkrete Angebot vorgesehen
- eine konkrete Sockenauswahl oder vergleichbare Warenangabe, ohne daraus eine separate dauerhafte Waren-ID oder ein allgemeines Wareninventar zu machen

### 6.1 Zusatzoptionen

Zusatzoptionen können zusätzliche Vergütung erzeugen, z. B. besondere Nutzungs-/Tragebedingungen.

Zusatzoptionen werden bei der späteren Vergütung **binär** bewertet:

- erfüllt = 100 % der vereinbarten Extra-Vergütung
- nicht erfüllt = 0 %
- keine Teilvergütung einzelner Extras

### 6.2 Kombiangebote

Kombiangebote sind zulässig.

Enthält ein Kombiangebot Socken mit Tragezeit, gilt der gesamte Auftrag für die Parallelitätsregeln als Socken-Trageauftrag.

### 6.3 Angebot duplizieren

Der Admin kann ein Angebot vollständig duplizieren.

Die Kopie übernimmt insbesondere:

- Grunddaten
- Preise
- Extras
- Nachweisregeln
- Prüfregeln
- Versandvorgaben
- sonstige Konfiguration

Die Kopie wird anschließend unabhängig bearbeitet.

### 6.4 Aktivierung

Angebote werden ausschließlich manuell aktiviert/deaktiviert.

Nicht vorgesehen:

- automatische Aktivierung nach Startdatum
- automatische Deaktivierung nach Enddatum
- wiederkehrende Angebotszeitfenster

### 6.5 Deaktivierung

Bei Deaktivierung:

- keine neuen Anfragen
- bestehende bestätigte/laufende Aufträge bleiben vollständig bestehen
- Versand, Wareneingang, Prüfung und Auszahlung bestehender Aufträge laufen weiter
- Warteliste wird eingefroren
- bestehende Wartelistenpositionen bleiben erhalten
- keine neuen Wartelisten-Zuteilungen bis zur Reaktivierung
- nach Reaktivierung läuft die Warteliste an gleicher Stelle weiter

### 6.6 Löschen

Der Admin darf Angebote vollständig löschen, auch wenn bereits Aufträge existieren.

Vor Löschung ist eine ausdrückliche Sicherheitsabfrage erforderlich.

Bereits existierende Aufträge müssen weiterhin vollständig funktionieren und alle benötigten Daten aus ihrem Auftragssnapshot besitzen.

### 6.7 Mehrfachnutzung

Eine Anbieterin darf dasselbe Angebot beliebig oft nacheinander nutzen.

Dasselbe Angebot darf von derselben Anbieterin jedoch **nicht mehrfach gleichzeitig offen/aktiv** sein.

---

## 7. Angebotskapazität und Warteliste

Pro Angebot kann der Admin eine maximale Anzahl gleichzeitig offener/aktiver Aufträge festlegen.

Ist das Limit erreicht:

- Angebot bleibt sichtbar
- Kennzeichnung „derzeit voll“
- neue direkte Anfrage nicht möglich
- Anbieterin kann sich auf Warteliste setzen

### 7.1 Wartelistenreihenfolge

Strikt FIFO:

- frühester Eintrag zuerst
- keine Priorisierung nach Zuverlässigkeit
- kein Admin-Ranking

### 7.2 Freier Platz

Wird ein Platz frei:

- erste berechtigte Person erhält exklusiv 24 Stunden Vorrecht
- nur sie kann in dieser Zeit den Platz nutzen
- wird die Frist nicht genutzt, wird sie vollständig von dieser Warteliste entfernt
- danach rückt die nächste Person nach

### 7.3 Persönliches Auftragslimit während Warteliste

Hat die erste Person bereits ihr persönliches Maximum erreicht:

- sie wird vorübergehend übersprungen
- sie verliert ihre Wartelistenposition nicht
- der freie Platz kann an die nächste wartende Person gehen
- sobald wieder ein persönlicher Auftragsslot frei ist, wird sie erneut berücksichtigt

Eine 24-Stunden-Wartelistenreservierung zählt noch nicht zum persönlichen Auftragslimit.

### 7.4 Selbst austragen

Eine Anbieterin kann sich jederzeit selbst austragen.

Bei späterer Neueintragung beginnt sie am Ende der Liste.

### 7.5 Mehrere Wartelisten

Eine Anbieterin darf auf beliebig vielen Wartelisten verschiedener Angebote stehen.

Wartelisten zählen nicht gegen das Auftragslimit.

Für mehrere Socken-Trageangebote gilt:

- mehrere Wartelisten sind erlaubt
- die vorgesehenen Zeiträume müssen miteinander vereinbar sein
- entstehen später Konflikte, wird die später zugeteilte Reservierung automatisch auf den nächstmöglichen konfliktfreien Zeitraum verschoben
- die 24-Stunden-Annahmefrist startet erst, wenn der neue Zeitraum tatsächlich konfliktfrei verfügbar ist

---

## 8. Auftragserstellung und Bestätigung

### 8.1 Anfrage

Die Anbieterin:

1. wählt ein Angebot
2. konfiguriert Optionen
3. schlägt ein konkretes Startdatum vor
4. sieht vor dem Absenden eine vollständige Zusammenfassung
5. bestätigt ausdrücklich die Zusammenfassung
6. sendet die Anfrage

Die Zusammenfassung zeigt mindestens:

- Startdatum
- Grundvergütung
- Extras
- Nachweispflichten
- Zeitfenster
- Versandvorgaben
- relevante Bedingungen

### 8.2 Rückzug vor Adminbestätigung

Solange der Admin nicht bestätigt hat, kann die Anbieterin den Antrag jederzeit folgenlos zurückziehen.

### 8.3 Adminentscheidung

Der Admin kann:

- bestätigen
- ablehnen
- anderen Starttermin vorschlagen

Bei Ablehnung ist eine Begründung optional.

Wenn eine Begründung angegeben wird, wird sie der Anbieterin angezeigt.

Ein abgelehnter Antrag kann vom Admin später wieder auf „offen“ gesetzt werden.

### 8.4 Gegenangebot beim Termin

Passt das vorgeschlagene Datum nicht:

- Admin schlägt neues Datum vor
- Anbieterin muss ausdrücklich zustimmen
- erst dann ist der Termin verbindlich

### 8.5 Verbindlicher Start

Nach beiderseitiger Terminbestätigung ist der Starttermin verbindlich.

Die Anbieterin kann ihn nicht einseitig ändern.

Wird der verpflichtende Startnachweis nicht rechtzeitig erbracht:

- Auftrag endet automatisch als „nicht erfüllt / nicht angetreten“
- Vorgang wird in der Zuverlässigkeitshistorie berücksichtigt

### 8.6 Admin-Stornierung vor Start

Der Admin darf einen bestätigten Auftrag vor Start stornieren.

Pflicht:

- Begründung
- sofortige Benachrichtigung
- Protokollierung in der Auftragshistorie

---

## 9. Persönliches Auftragslimit

Maximal **5** gleichzeitig zählende Aufträge pro Anbieterin.

Zum Limit zählen:

- bestätigte zukünftige Aufträge
- gestartete/aktive Aufträge

Nicht zum Limit zählen:

- noch nicht bestätigte Anfragen
- Wartelistenreservierungen
- Aufträge, deren Trage-/Durchführungsphase vollständig beendet ist und die sich nur noch in Versand/Wareneingang/Prüfung befinden

---

## 10. Sonderregel Socken-Trageaufträge

Es darf immer nur **ein Sockenauftrag mit Tragezeit aktiv** sein.

Andere Auftragstypen dürfen parallel laufen, soweit das allgemeine Maximum von 5 eingehalten wird.

Kombiangebote mit Socken-Tragezeit zählen als Socken-Trageauftrag.

### 10.1 Mehrere zukünftige Sockenaufträge

Mehrere zukünftige Socken-Trageaufträge dürfen bereits bestätigt sein, wenn sich ihre geplanten Tragezeiträume nicht überschneiden.

### 10.2 Verlängerung und Verschiebung

Verlängert oder verschiebt sich ein laufender Sockenauftrag:

- nachfolgende bestätigte Sockenaufträge dürfen nicht parallel starten
- der nächste Auftrag wird automatisch auf den nächstmöglichen freien Start verschoben
- die Anbieterin kann diese automatische Verschiebung nicht ablehnen
- keine erneute Terminbestätigung erforderlich
- alle weiteren Folgeaufträge werden kaskadierend verschoben
- Reihenfolge bleibt erhalten
- Admin und Anbieterin werden informiert

Es gibt keine maximale Gesamtdauer eines Socken-Trageauftrags.

---

## 11. Aktivierungstag und Tragetage

Der bestätigte Starttag ist **Aktivierungs-/Vorbereitungstag**.

An diesem Tag:

- Anbieterin bestätigt den Start
- verpflichtendes Startfoto
- Startfoto benötigt einen 10-Minuten-Einmalcode

Für den Beginn von Tag 1 ist **keine vorherige Adminfreigabe des Startfotos erforderlich**. Der rechtzeitige Upload des Startfotos genügt zunächst. Eine spätere Ablehnung wird nach der unten festgelegten Reset-Logik behandelt.

Der erste vollständige Tragetag beginnt am folgenden Kalendertag.

Beispiel:

- Aktivierung 20.09.
- Tag 1 = 21.09.

Ein Tragetag ist ein Kalendertag in `Europe/Berlin`.

Es gibt keine allgemeine Mindestanzahl Trage-Stunden pro Kalendertag, sofern das jeweilige Angebot nichts Konkreteres verlangt.

---

## 12. Nachweise

### 12.1 Nachweiskonfiguration

Der Admin kann pro Angebot festlegen:

- Anzahl Bilder
- mehrere Nachweise pro Tag
- mehrere Zeitfenster
- optionale oder verpflichtende Texte
- weitere Pflichtdaten
- Gesicht sichtbar: ja/nein und bei welchen Nachweisen
- konkrete Bildanforderungen

Alle Anforderungen müssen **vor Annahme des Angebots vollständig sichtbar** sein.

### 12.2 Live-Kamera

Nachweisfotos dürfen nur über die Live-Kamera-Funktion der Webanwendung erstellt werden.

Nicht zulässig:

- Galerie-Upload
- normaler Datei-Upload für Nachweisfotos

### 12.3 Einmalcode

Jeder relevante Nachweis erhält einen zufälligen Code mit **10 Minuten Gültigkeit**.

Der Code muss im Bild sichtbar sein.

Zulässige Methoden:

- handschriftlich auf Zettel
- auf zweitem Gerät sichtbar
- digitales Overlay durch die Webanwendung

Auch das Startfoto benötigt diesen Code.

Ist ein Code abgelaufen:

- Nachweis mit diesem Code ungültig
- Ereignis wird protokolliert
- neuer Code muss erzeugt werden

### 12.4 Mehrere Zeitfenster

Ein Angebot kann mehrere verpflichtende Nachweisfenster pro Tag haben.

Jedes Fenster besitzt:

- eigenen Code
- eigenen Nachweis
- eigenen Prüfstatus

Fehlt ein verpflichtendes Fenster, ist der gesamte Tag ungültig.

### 12.5 Erinnerungen

Mehrstufige Erinnerungen:

- zu Beginn des Fensters
- ca. 30 Minuten vor Ende
- ca. 10 Minuten vor Ende

Kanäle:

- Webapp
- E-Mail
- Push, sofern aktiviert

Ist Push deaktiviert oder fehlerhaft, bleiben Webapp und E-Mail bestehen.

### 12.6 Prüfung

Jeder Nachweis wird einzeln geprüft.

Status mindestens:

- eingereicht
- akzeptiert
- abgelehnt

Eine Ablehnung benötigt eine Begründung.

### 12.7 Nachreichungen

Regulär maximal **2 Nachreichversuche** nach einem abgelehnten Nachweis.

Für jeden regulären Versuch gilt grundsätzlich eine Frist von **2 Stunden ab Ablehnung**.

Der Admin kann unbegrenzt viele zusätzliche Nachreichversuche manuell freigeben.

Zusätzliche Freigaben werden protokolliert.

Wird eine laufende 2-Stunden-Nachreichfrist versäumt, gilt der betreffende Nachweis als endgültig fehlgeschlagen, der Tag wird ungültig und das Versäumnis wird zusätzlich als Zuverlässigkeitsverstoß dokumentiert.

### 12.8 Späte Adminprüfung

Bei später Ablehnung wird unterschieden:

**Technischer/formaler Fehler**
- 2 Stunden ab Ablehnung zur Nachreichung

**Inhaltlich nicht mehr reproduzierbarer Fehler**
- keine künstliche Nachstellung
- Tag wird als ungültig behandelt
- Ersatz-/Neustartlogik greift

### 12.9 Ungültige Tage

Ein ungültiger Tag:

- zählt nicht
- wird am Ende angehängt/ersetzt
- Auftrag verlängert sich

Jeder weitere ungültige Ersatz-Tag wird erneut ersetzt.

### 12.10 Unterbrechungen und Serienreset

Innerhalb einer laufenden Serie:

- erste Unterbrechung/ungültiger Tag: bisherige gültige Tage bleiben erhalten, ungültiger Tag wird ersetzt
- zweite Unterbrechung: gesamte erforderliche Serie startet wieder bei Tag 1

Bei Serienreset:

- alte akzeptierte Nachweise bleiben archiviert
- sie zählen nicht mehr für die neue Serie
- dieselbe Ware wird weiterverwendet
- keine neue Ware erforderlich

Nach einem Reset gelten dieselben Regeln erneut.

### 12.11 Startfoto später abgelehnt

Wird das Startfoto später endgültig abgelehnt:

- aktuelle Serie ungültig
- vorhandene Tagesnachweise werden archiviert und zählen nicht mehr
- neues gültiges Startfoto erforderlich
- danach neue Serie ab Tag 1

---

## 13. Bildinhalte und Speicherung

### 13.1 Personen auf Bildern

Auf Nachweisfotos darf ausschließlich die Anbieterin selbst erkennbar sein.

Andere Personen dürfen nicht erkennbar abgebildet werden.

### 13.2 Gesicht

Ob das Gesicht sichtbar sein muss, wird pro Angebot festgelegt.

Es gibt:

- keine automatische Gesichtserkennung
- keinen biometrischen Vergleich
- keinen manuellen Identitätsabgleich zwischen Gesichtsbildern als eigene Prüfung

### 13.3 Zulässige Inhalte

Grundsätzlich sind alle legalen Bildinhalte volljähriger Anbieterinnen zulässig, sofern keine weiteren Personen betroffen sind und die Inhalte rechtlich zulässig sind.

### 13.4 Metadaten

Nachweisbilder werden einschließlich der vom Aufnahmeprozess gelieferten EXIF-/Metadaten gespeichert.

- keine automatische EXIF-Bereinigung
- keine automatische GPS-Entfernung
- keine automatische Re-Encoding-Strategie, deren Zweck das Entfernen von Metadaten ist
- keine erfundenen Metadaten, falls Browser/Kamera keine liefert

Bei einem digitalen Code-Overlay ist die daraus erzeugte Nachweisdatei die kanonische eingereichte Datei.

### 13.5 Dauerhafte Speicherung

Nachweisfotos und sonstige auftragsbezogene Bilder werden dauerhaft gespeichert.

Einmal eingereichte Nachweisfotos dürfen weder Anbieterin noch Admin löschen.

Sie dürfen nur:

- akzeptiert
- abgelehnt
- als ungültig markiert

werden.

Dauerhaft erhalten bleiben:

- Datei
- Zeitstempel
- SHA-256-Hash
- Prüfstatus
- Zuordnung zum Auftrag

---

## 14. Nachträgliche Änderungen laufender Aufträge

Der Admin darf Anforderungen eines bereits angenommenen oder laufenden Auftrags nachträglich ändern oder erweitern.

Die Anbieterin kann diese Änderung **nicht ablehnen**.

### 14.1 Vergütung bei Änderung

Der Admin entscheidet individuell:

- keine Zusatzvergütung
- fixer Zuschlag
- individuelle Zusatzvergütung

### 14.2 Wirksamkeitszeitpunkt

Der Admin legt fest:

- sofort
- nächstes Nachweisfenster
- nächster Kalendertag
- konkretes Datum/Uhrzeit

### 14.3 Benachrichtigung

Pflichtkanäle:

- Webapp
- E-Mail
- Push, sofern aktiviert

### 14.4 Versionsverhalten

In der fachlichen Auftragsansicht wird nur die aktuelle Fassung der Anforderungen gespeichert und angezeigt.

Frühere Anforderungsfassungen sind nicht als Versionen abrufbar.

Das Audit-Log darf den Änderungsvorgang, Zeitpunkt und betroffenen Datensatz protokollieren, soll jedoch für diese Fachfunktion keine separate rekonstruierbare Anforderungsversionsverwaltung bilden.

---

## 15. Abbruch und Stornierung

### 15.1 Adminabbruch eines laufenden Auftrags

Nur aus konkretem, dokumentiertem Grund, z. B.:

- Regelverstoß
- Manipulationsverdacht
- mehrfach ungültige Nachweise
- Sicherheitsproblem
- objektiv nicht mehr erfüllbare Voraussetzung

### 15.2 Vergütung bei Adminabbruch

Abhängig vom Abbruchgrund.

Möglich:

- vollständiger Vergütungsentfall bei Pflichtverletzung/Manipulation
- anteilige Vergütung
- vollständige Vergütung bei vom Betreiber verursachten/organisatorischen Gründen

Entscheidung wird dokumentiert.

### 15.3 Freiwilliger Abbruch durch Anbieterin

Die Anbieterin darf einen gestarteten Auftrag selbst abbrechen.

Pflicht:

- Grund angeben
- Abbruch fließt in Zuverlässigkeit ein

Vergütung:

- grundsätzlich 0 €
- auch bereits absolvierte Tage werden nicht anteilig vergütet

Bereits verwendete Ware darf nach freiwilligem Abbruch nicht für einen neuen Auftrag erneut eingesetzt werden.

Es gibt dafür keine separate Waren-ID; die Nachvollziehbarkeit erfolgt über Auftrag, Startfoto, Nachweise und Historie.

---

## 16. Nachrichten

Es gibt interne Nachrichten **nur innerhalb eines konkreten Auftrags**.

Nicht vorgesehen:

- allgemeiner Support-Chat
- frei gestartete Unterhaltung ohne Auftrag
- globale Inbox-Konversationen ohne Auftragsbezug
- eigener Chat für Auszahlungen

Auftragschat unterstützt:

- Nachrichten
- Anhänge
- Lesestatus
- Benachrichtigungen
- Adminsicht

Nach Abschluss eines Auftrags:

- Chat bleibt 7 Tage beschreibbar
- danach automatisch schreibgeschützt
- Verlauf bleibt einsehbar

Auszahlungsinformationen werden ausschließlich direkt am Auszahlungsantrag angezeigt.

---

## 17. Zuverlässigkeit

Es gibt keinen öffentlichen oder dauerhaften 0–100-Score.

Stattdessen gibt es regelbasierte Zuverlässigkeitseinschränkungen. Definierte Verstöße können die vorgesehenen Einschränkungen automatisch auslösen; die konkreten Regeln müssen zentral konfigurierbar und technisch nachvollziehbar sein.

Mögliche Folgen:

- reduziertes persönliches Auftragslimit
- temporäre/fortbestehende Auftragsbeschränkung
- Ausschluss bestimmter Angebote

Die Anbieterin sieht:

- konkreten Grund
- aktive Einschränkung
- ggf. weitere Bedingungen
- Fortschritt der Bewährungsphase
- vollständige Historie früherer Einschränkungen und Bewährungsphasen

### 17.1 Bewährungsphase

Eine Einschränkung endet **nicht automatisch nach Zeit**.

Erforderlich:

1. 5 vollständig fehlerfreie Aufträge direkt hintereinander
2. danach manuelle Aufhebung durch Admin

Ein Bewährungsauftrag zählt nur, wenn er **vollständig ohne Fehler oder Abweichung** abgeschlossen wurde, insbesondere:

- keine verspäteten Nachweise
- keine Nachreichung
- keine Versandverspätung
- keine abgelehnten Extras
- keine sonstige Abweichung von den verbindlichen Auftragsanforderungen

Ein Auftrag mit einem kleineren Fehler zählt nicht als erfolgreicher Bewährungsauftrag. Ein neuer relevanter Zuverlässigkeitsverstoß setzt die laufende Serie vollständig auf 0.

Anzeige z. B. „3 von 5 erfolgreich“.

Es gibt keine freien internen Admin-Notizen zu Anbieterinnen.

---

## 18. Versand

Nach vollständiger Durchführung/Tragephase wechselt der Auftrag in „Versand erforderlich“.

Versandfrist:

- 24 Stunden ab diesem Status

Wird die Frist verpasst:

- Auftrag bleibt offen
- Versand weiterhin möglich
- Verspätung wird als Zuverlässigkeitsverstoß dokumentiert

### 18.1 Versandkosten

Die Anbieterin trägt die Versandkosten zum Betreiber selbst.

Keine Erstattung.

### 18.2 Tracking

Pro Angebot konfigurierbar:

- verpflichtend
- optional
- nicht vorgesehen

### 18.3 Versandnachweis

Ohne Trackingpflicht:

- Paketfoto
- Versand-/Annahmebeleg

Bei Trackingpflicht zusätzlich:

- Trackingnummer

Der Versandbeleg muss über Live-Kamera aufgenommen werden.

Keine Galerie-/Dateiuploads für diesen Beleg.

Mindestens sichtbar:

- Versanddatum
- Versanddienstleister

Trackingnummer nur, wenn das Angebot sie verlangt.

Bei leicht unscharfem Beleg gilt:

- wenn Pflichtangaben klar erkennbar: akzeptierbar
- wenn Pflichtangaben nicht lesbar: Ablehnung
- dann 2 Stunden zur neuen Live-Aufnahme

### 18.4 Standardverpackung

Für alle Sendungen gelten feste Mindestvorgaben:

- sichere Verpackung
- Schutz vor Feuchtigkeit
- Schutz vor Transportschäden
- Ware innerhalb des Pakets getrennt/geeignet verpacken
- Auftragsnummer im Paket beilegen

---

## 19. Eigentum und Versandrisiko

Eigentum an der eingesandten Ware geht **mit Versand durch die Anbieterin** auf den Betreiber über.

Versandrisiko bleibt dennoch bis zum bestätigten Wareneingang vollständig bei der Anbieterin.

Bei Verlust oder Transportschaden vor bestätigtem Wareneingang trägt die Anbieterin das Risiko.

---

## 20. Wareneingang und Zuordnung

Wareneingang wird immer manuell vom Admin bestätigt.

Tracking ersetzt die manuelle Eingangsbestätigung nicht.

Für den Wareneingang ist kein zusätzliches Pflichtfoto vorgesehen.

### 20.1 Auftragsnummer im Paket

Die Auftragsnummer muss im Paket liegen.

Außen ist keine Auftragskennzeichnung erforderlich.

### 20.2 Fehlende Auftragsnummer

Fehlt die Auftragsnummer, versucht der Admin eine eindeutige Zuordnung anhand von:

- Absender
- Tracking
- Versanddatum
- offenen Aufträgen

Gelingt die Zuordnung, wird normal weitergearbeitet.

Gelingt keine eindeutige Zuordnung:

- keine Vergütung
- Ware verbleibt beim Betreiber
- Vorgang wird als nicht zuordenbare Sendung dokumentiert
- keine automatische Rücksendung
- keine nachträgliche Zuordnungsfrist

---

## 21. Warenprüfung

Nach bestätigtem Wareneingang erfolgt die finale Warenprüfung.

Prüfkategorien:

1. Aussehen
2. Geruch
3. Geschmack
4. Nachweise
5. Extras

Je Kategorie:

- bestanden / nicht bestanden
- 0–10 Punkte
- optionaler Kommentar

Gesamt maximal 50 Punkte.

Bestimmte Kategorien können pro Angebot als KO-Kriterium markiert werden.

Scheitert ein KO-Kriterium:

- Vergütung kann vollständig entfallen, unabhängig von Gesamtpunktzahl

Nicht-KO-Fehler wirken über die reguläre Punkte-/Vergütungslogik.

### 21.1 Punktebänder

Pro Angebot wird festgelegt, **ob und wie** die Gesamtpunktzahl die Grundvergütung beeinflusst. Wird eine punktbasierte Vergütung verwendet, können feste Prozentbänder definiert werden, z. B.:

- 45–50 Punkte = 100 %
- 40–44 Punkte = 90 %
- 35–39 Punkte = 75 %
- darunter nach Angebotskonfiguration

Die konkrete Staffelung ist Angebotskonfiguration.

### 21.2 Grundvergütung und Extras getrennt

Grundvergütung und Zusatzoptionen werden getrennt bewertet.

- Qualitäts-/Punktelogik bestimmt die Grundvergütung nach Angebotsregel
- jedes gebuchte Extra wird separat binär bewertet
- erfülltes Extra = 100 %
- nicht erfülltes Extra = 0 %

### 21.3 Abweichende Ware

Bei Ware, die nicht wie vereinbart ist, kann der Admin abhängig vom Fall:

- Korrektur/Nachbesserung verlangen
- Vergütung reduzieren
- Ware vollständig ablehnen

Grund muss dokumentiert und angezeigt werden.

Eine interne Einspruchs-/Appeal-Funktion gegen die finale Vergütungsentscheidung ist nicht vorgesehen.

---

## 22. Vergütungsfreigabe

Vergütung wird erst final, wenn:

- Durchführung/Tragezeit abgeschlossen
- erforderliche Nachweise akzeptiert
- Ware versendet
- Ware physisch eingegangen
- finale Warenprüfung abgeschlossen

Nach erfolgreicher finaler Prüfung wird die berechnete Vergütung **sofort als verfügbares Wallet-Guthaben** freigegeben.

Keine zusätzliche Pending-Wartezeit.

Es werden keine automatischen Einzelabrechnungen oder monatlichen PDF-Sammelabrechnungen erstellt.

Auftrags-, Wallet- und Auszahlungshistorie dienen als interne Nachvollziehbarkeit.

---

## 23. Vollständige Warenablehnung und Rücksendung

Bei vollständiger Ablehnung kann die Anbieterin Rücksendung auf eigene Kosten verlangen.

Frist für Rücksendeanforderung:

- 3 Kalendertage

Ohne Anforderung innerhalb dieser Frist verfällt die Rücksendeoption.

Nach rechtzeitiger Anforderung hat die Anbieterin weitere **24 Stunden**, um die Rücksendung zu ermöglichen.

Zulässige Varianten:

1. eigenes gültiges Rücksendeetikett bereitstellen
2. Betreiber teilt tatsächliche Versandkosten mit und Anbieterin überweist diese separat

Wallet wird für Rücksendekosten nicht verwendet.

Verstreichen die 24 Stunden:

- Rücksendeoption verfällt endgültig
- Ware verbleibt beim Betreiber
- keine weitere automatische Nachfrist

---

## 24. Wallet

Wallet-Bereiche müssen mindestens die fachlich notwendigen Zustände für verfügbar/reserviert/ausgezahlt abbilden.

### 24.1 Freigabe

Nach erfolgreicher Warenprüfung wird die Vergütung sofort verfügbar.

### 24.2 Manuelle Adminänderung

Der Admin darf den aktuellen Wallet-Kontostand direkt manuell überschreiben.

Dies ist ausdrücklich keine normale Korrekturbuchungslogik.

Pflicht im Audit-Log:

- alter Kontostand
- neuer Kontostand
- Zeitpunkt
- Adminaktion

Das System muss trotz direkter Balance-Overrides eine verständliche Darstellung der Wallet-Historie gewährleisten.

---

## 25. Auszahlungen

### 25.1 Auszahlungstage

Auszahlungen werden freitags bearbeitet.

Cutoff:

- Donnerstag 23:59 Uhr `Europe/Berlin`

Spätere Anträge werden auf den nächsten Freitag verschoben.

### 25.2 Betrag

- kein Mindestbetrag
- jeder positive verfügbare Betrag darf beantragt werden
- frei wählbarer Teilbetrag
- nicht zwingend gesamtes Wallet

### 25.3 Eine offene Auszahlung

Pro Anbieterin maximal eine offene Auszahlung gleichzeitig.

Neues Guthaben darf weiter entstehen, aber ein neuer Antrag erst nach Abschluss/Ablehnung/Storno der vorherigen Auszahlung.

### 25.4 Reservierung

Bei Antrag:

- beantragter Betrag sofort reservieren
- nicht mehr als verfügbar ausgebbar
- verhindert Doppelauszahlung

### 25.5 Auszahlungsmethoden

- Banküberweisung
- PayPal

Die Anbieterin wählt die Methode pro Antrag.

Der Auszahlungsantrag speichert einen unveränderlichen Snapshot der bei Antragstellung verwendeten Auszahlungsdaten.

Spätere Änderungen wirken nur auf zukünftige Anträge.

### 25.6 Änderung der Auszahlungsdaten

Normale Änderung:

- 24 Stunden Sicherheitssperre bis zur Nutzung für neue Auszahlungsanträge

Abweichender Kontoinhaber/PayPal-Name:

- zusätzliche ausdrückliche Adminfreigabe erforderlich

Standard:

- Name der Anbieterin und Kontoinhaber/PayPal-Name sollen übereinstimmen
- Abweichung nur nach Adminfreigabe

### 25.7 Gebühren

Etwaige PayPal-Auszahlungsgebühren trägt immer der Betreiber.

Die Anbieterin erhält den beantragten Betrag vollständig.

### 25.8 Adminprüfung

Jeder Antrag wird vom Admin manuell geprüft.

Prüfung umfasst mindestens:

- Betrag
- Methode
- Auszahlungssnapshot

### 25.9 Selbststorno

Anbieterin kann einen Auszahlungsantrag jederzeit vor Adminfreigabe selbst stornieren.

Reserviertes Guthaben wird sofort wieder verfügbar.

### 25.10 Ablehnung

Admin kann ablehnen.

Pflicht:

- sichtbarer Ablehnungsgrund
- reservierter Betrag sofort zurück auf verfügbar

Danach kann sofort neuer Antrag gestellt werden.

### 25.11 Externe Zahlung

Die eigentliche Bank-/PayPal-Zahlung erfolgt außerhalb der Plattform manuell durch den Admin.

Die Plattform verwaltet nur Status, Reservierung, Historie und Bestätigung.

### 25.12 Statuskette

Fachliche Grundkette:

1. beantragt
2. reserviert
3. geprüft
4. freigegeben
5. extern ausgeführt
6. Admin bestätigt „Zahlung ausgeführt“
7. nach 24 Stunden automatisch „abgeschlossen“

### 25.13 Fehlgeschlagene externe Zahlung

Admin kann:

- Zahlung erneut versuchen
- Auszahlung beenden und reservierten Betrag wieder verfügbar machen

### 25.14 Wiederöffnen durch Admin

Der Admin darf eine Auszahlung **in jedem Status jederzeit** wieder öffnen, zurücksetzen oder verändern, auch nach „abgeschlossen“.

Jeder Eingriff wird im Audit-Log protokolliert.

---

## 26. Dokumente und Einwilligungen

Allgemeine Dokumente wie AGB, Datenschutzerklärung und sonstige allgemeine Einwilligungen werden **einmal bei Registrierung** bestätigt.

Spätere Dokumentänderungen lösen systemseitig keine erneute Pflichtbestätigung aus.

Das System darf Dokumente verwalten, darf aber keinen verpflichtenden Re-Consent-Workflow für spätere Fassungen erzwingen.

---

## 27. Audit-Log

Es gibt ein vollständiges internes Audit-Log für wichtige Adminaktionen.

Zu protokollieren sind insbesondere:

- Angebotsänderungen
- Angebotslöschungen
- Auftragsstornierungen
- Auftragsabbrüche
- Nachweisentscheidungen
- nachträgliche Auftragsänderungen
- Vergütungsentscheidungen
- Kontodeaktivierungen
- Wallet-Overrides
- Auszahlungsänderungen
- Wiederöffnungen von Auszahlungen
- sonstige sicherheits-/finanzrelevante Adminaktionen

Mindestens:

- Zeitpunkt
- Aktion
- betroffener Datensatz
- Adminbezug

Es gibt keine freien internen Profilnotizen.

---

## 28. Auftragsabschluss

Ein als **abgeschlossen** markierter Auftrag darf nicht wieder geöffnet werden.

Der Status ist auf Auftragsebene endgültig.

Davon unabhängig dürfen Wallet- und Auszahlungsprozesse entsprechend ihren eigenen Regeln später verändert werden.

---

## 29. Benachrichtigungen

Das System unterstützt:

- Webapp-Benachrichtigungen
- E-Mail
- Push, sofern vom Benutzer aktiviert/technisch verfügbar

Besonders wichtige Ereignisse wie:

- Terminänderung
- neue/veränderte Auftragsanforderung
- Nachweisfristen
- automatische Socken-Terminverschiebung
- relevante Statusänderungen

sollen über die dafür festgelegten Kanäle ausgeliefert werden.

---

## 30. Bekannte Abweichungen des aktuellen Codes, die zu entfernen sind

Der bestehende Stand enthält Funktionen, die dieser Spezifikation widersprechen. Diese gelten nicht als gewünschtes Endverhalten.

Mindestens zu korrigieren:

1. **Admin-2FA**
   - aktuell vorhanden/erzwungen
   - muss fachlich entfernt werden

2. **Mehrere Adminrollen und Permissions**
   - aktuell `superadmin/admin/staff/accounting`
   - Ziel: ein Admin-Konto ohne Rollenmatrix

3. **Identitäts-/Ausweisdokumentprüfung**
   - aktuell vorhanden und teilweise für Auszahlung verlangt
   - Ziel: keine Ausweis-/Identitätsprüfung

4. **Allgemeine Nachrichten**
   - aktuell kann eine Conversation ohne `order_id` erzeugt werden
   - Ziel: Kommunikation ausschließlich innerhalb konkreter Aufträge

5. **Mindest-Auszahlungsbetrag**
   - aktuell standardmäßig 10 €
   - Ziel: kein Mindestbetrag

6. **Nur Bankauszahlung**
   - aktuell primär IBAN/Banküberweisung
   - Ziel: Bank + PayPal

7. **Terminale Auszahlung**
   - aktuell können abgeschlossene Status nicht wieder geändert werden
   - Ziel: Admin darf Auszahlungen jederzeit wieder öffnen

8. **Automatische Dateilöschung**
   - aktuell existiert eine Purge-Logik für Proofs/Prechecks/Anhänge
   - Ziel: Nachweisfotos und auftragsbezogene Bilder dauerhaft speichern

9. **Bild-Sanitizing/Re-Encoding**
   - aktuell werden Bilder neu kodiert und Metadaten dabei entfernt
   - Ziel: Nachweisbilder einschließlich vorhandener Metadaten aufbewahren

10. **Profil-Selbständerung**
    - aktuell können Name und Adresse teilweise selbst geändert werden
    - Ziel: Anbieterin nur E-Mail + Telefonnummer selbst; andere Stammdaten nur Admin

11. **Dokumentversionen/Re-Consent**
    - vorhandene Versionsmodelle dürfen nicht zu einer erneuten Pflichtbestätigung späterer Fassungen führen

12. **Auftrags-/Nachweis-/Sockenlogik**
    - der bestehende Code bildet die hier definierte Serien-, Ersatz-, Neustart-, Terminverschiebungs- und Einmalcode-Logik noch nicht vollständig ab

13. **Wartelisten**
    - die vollständige FIFO-/24h-/Skip-/Socken-Konfliktlogik muss gemäß diesem Dokument umgesetzt werden

14. **Qualitätsprüfung**
    - die fünf Kategorien, KO-Regeln, 0–10-Punkte sowie getrennte Extra-Vergütung müssen konsistent umgesetzt werden

15. **Rücksendungslogik**
    - 3-Tage-Anforderung + 24-Stunden-Zahlung/Label + keine Wallet-Nutzung muss umgesetzt werden

---

## 31. Architekturgrundsätze für die weitere Umsetzung

### 31.1 Serverseitige Wahrheit

Alle kritischen Regeln werden serverseitig erzwungen:

- Preise
- Auftragslimits
- Terminüberschneidungen
- Socken-Slot
- Nachweisfristen
- Codes
- Auszahlungslimits
- Wartelistenposition
- Vergütung
- Statuswechsel

Frontend-JavaScript darf nur unterstützen, nie allein entscheiden.

### 31.2 Race-Condition-Schutz

Bei folgenden Abläufen sind Transaktionen/Locks oder gleichwertige Schutzmechanismen erforderlich:

- letzter Angebotsplatz
- Wartelisten-Zuteilung
- persönliches 5-Aufträge-Limit
- einziger Socken-Trageplatz
- Wallet-Reservierung
- Auszahlungsantrag
- Wallet-Override
- Statusübergänge mit finanziellen Folgen

### 31.3 Auftragssnapshot

Jeder Auftrag muss bei Entstehung alle für ihn notwendigen Angebotsdaten kopieren, damit spätere Änderung oder Löschung des Angebots bestehende Aufträge nicht zerstört.

Ausnahme:

- der Admin darf den **konkreten laufenden Auftrag** nach den Regeln in Abschnitt 14 nachträglich verändern

### 31.4 Statushistorie

Relevante Statuswechsel müssen nachvollziehbar bleiben.

### 31.5 Keine versteckten Platzhalter

Produktive Oberflächen dürfen keine offensichtlichen Lorem-Ipsum-/Demo-/Dummy-Inhalte enthalten.

---

## 32. Teststrategie

Der Auftraggeber testet keine Zwischenstände.

Während der Entwicklung:

- automatisierte Tests weiter ausbauen
- Feature-/Integrationstests für Statusmaschinen und Fristen
- keine Aufforderung an den Auftraggeber, Zwischenstände manuell zu testen

Erst wenn der vollständig vereinbarte Funktionsumfang umgesetzt ist, erfolgt der gemeinsame Endtest.

Besonders zu testen:

- 5-Aufträge-Limit
- Socken-Slot und kaskadierende Verschiebungen
- Serienreset
- Nachweisfenster
- 10-Minuten-Codes
- 2 reguläre + unbegrenzte Admin-Nachreichfreigaben
- Wartelisten-FIFO und 24-Stunden-Vorrecht
- Versandfrist
- Warenprüfung/KO/Punkte/Extras
- Rücksendefristen
- Wallet-Reservierung
- Freitag/Cutoff
- Auszahlungssnapshot
- Auszahlungs-Wiederöffnung
- Account-Deaktivierung
- Unveränderlichkeit eingereichter Nachweisdateien
- Audit-Log

---

## 33. Definition of Done

Die Plattform ist erst dann fachlich fertig, wenn:

- sämtliche Regeln dieses Dokuments umgesetzt sind
- alte widersprechende Funktionen entfernt oder angepasst sind
- Datenbankmigrationen konsistent sind
- UI und Backend dieselben Regeln abbilden
- keine kritische Regel nur clientseitig geprüft wird
- alle relevanten Statusübergänge getestet sind
- keine Zwischenlösung als endgültige Umsetzung verbleibt
- README den tatsächlichen Projektzustand widerspiegelt
- keine in diesem Masterprompt ausdrücklich ausgeschlossene Funktion weiterhin als Pflichtworkflow erzwungen wird

---

## 34. Arbeitsweise für weitere Änderungen

Bei jeder zukünftigen Entwicklungsrunde:

1. zuerst `MASTERPROMPT.md` lesen
2. betroffene bestehende Implementierung prüfen
3. Widersprüche identifizieren
4. Datenmodell, Backendlogik, UI und Tests gemeinsam anpassen
5. keine neue Funktion einbauen, die bestehende Masterprompt-Regeln stillschweigend verändert
6. nach Änderungen die passenden Git-Pull-Befehle angeben

Der Masterprompt darf nur geändert werden, wenn der Auftraggeber eine neue oder abweichende Regel ausdrücklich festlegt.
