# Wear&Earn – Reverse-Shop-Ankaufsplattform

Produktionsorientierte Laravel-13-Webanwendung für den im Projekt definierten Reverse-Shop-/Ankaufsprozess.

> **Verbindliche Projektspezifikation:** [MASTERPROMPT.md](MASTERPROMPT.md) ist die maßgebliche fachliche Quelle. Bei Widersprüchen zwischen Code, README und Masterprompt gilt der Masterprompt.

## Umgesetzter Kernumfang

Der aktuelle Stand enthält insbesondere:

- Registrierung mit Geburtsdatum, 18+-Prüfung und E-Mail-Verifikation
- genau einen fachlichen Adminzugang ohne verpflichtende 2FA
- Anbieterinnenprofile mit getrennten Selbst-/Adminänderungsrechten
- Admin-Deaktivierung, Reaktivierung und Löschung/Anonymisierung von Konten
- Kategorien und vollständig konfigurierbare Ankaufangebote
- Zusatzoptionen mit Aufpreis, Abhängigkeiten, Ausschlüssen, Extra-Tagen und Extra-Nachweisen
- angebotsspezifische Felder, Nachweisfenster, Trackingmodus, KO-Kriterien und Punktebänder
- manuelle Angebotsaktivierung/-deaktivierung mit eingefrorener Wartelistenreservierung
- Angebotsduplizierung und Löschen bei erhaltenen Auftragssnapshots
- FIFO-Wartelisten mit exklusivem 24-Stunden-Vorrecht und Sockenkonfliktlogik
- Auftragsanfrage, Adminfreigabe, Termingegenvorschlag und verbindlicher Aktivierungstag
- persönliches Auftragslimit und separate Socken-Trage-Slot-Logik
- Startfoto sowie Tagesnachweise mit 10-Minuten-Codes
- echte Browser-Live-Kamera via `getUserMedia` für Pflichtnachweise
- optionales digitales Code-Overlay
- mehrere Nachweisfenster pro Tag
- Nachweisprüfung, reguläre Nachreichversuche und zusätzliche Adminfreigaben
- Ersatztage, Unterbrechungszählung und vollständiger Serienreset
- dauerhafte private Originalspeicherung von Nachweisen mit SHA-256
- Webapp-, E-Mail- und optionale Web-Push-Benachrichtigungen
- mehrstufige Erinnerungen für Nachweisfenster
- auftragsbezogene Nachrichten mit Anhängen und 7-Tage-Schreibfrist nach Abschluss
- Versandfrist, Paketfoto, Versandbeleg und angebotsspezifisches Tracking
- dokumentierter Eigentums- und Risikoübergang
- manueller Wareneingang und Verwaltung nicht zuordenbarer Sendungen
- finale Warenprüfung mit Aussehen, Geruch, Geschmack, Nachweisen und Extras
- 0–10 Punkte je Prüfkategorie, KO-Kriterien und konfigurierbare Vergütungsbänder
- getrennte binäre Bewertung gebuchter Extras
- sofortige Wallet-Freigabe der finalen Vergütung nach erfolgreicher Warenprüfung
- direkter Admin-Wallet-Override mit Audit-Log
- Bank- und PayPal-Auszahlungen, Teilbeträge, Freitag/Cutoff, Ziel-Snapshot und 24-Stunden-Sicherheitssperre
- wiederöffnbare Auszahlungen mit saldenkonsistenter Reservierungslogik
- Rücksendung vollständig abgelehnter Ware nach der festgelegten 3-Tage-/24-Stunden-Logik
- regelbasierte Zuverlässigkeitseinschränkungen mit 5 fehlerfreien Bewährungsaufträgen + manueller Adminaufhebung
- vollständige Status-/Audit-Historien für zentrale Vorgänge
- einmalige Dokumentzustimmung bei Registrierung ohne späteren Re-Consent-Zwang

## Technische Basis

- PHP 8.3+
- Laravel 13
- MySQL / MariaDB
- Blade + Vanilla CSS/JavaScript
- private Dateispeicherung für sensible Auftragsdateien
- Datenbank-Queue und Datenbank-Cache möglich
- kein Docker-Zwang
- kein Node-/Vite-Zwang für die produktive Oberfläche
- optionale Browser-Push-Zustellung über VAPID / Web Push

## Automatisierte Qualitätssicherung

GitHub Actions führt bei Änderungen aus:

1. Composer-Manifestprüfung
2. PHP-Syntaxprüfung
3. Dependency-Installation
4. frische Migrationen und Seeder als Installations-Smoke-Test
5. Route- und Scheduler-Smoke-Test
6. vollständige Blade-Kompilierung per `view:cache`
7. JavaScript-Syntaxprüfung für Anwendung und Service Worker
8. Laravel-Feature-/Regressionstests

Die Tests decken neben Authentifizierung, Datenschutz, Deadlines und Wallet insbesondere zentrale Masterprompt-Invarianten ab, z. B. Auftragssnapshots nach Angebotslöschung, ausdrückliche serverseitige Löschbestätigung, Live-Kamera-Pfade, FIFO-/Reservierungslogik, kaskadierende Socken-Terminverschiebungen inklusive reservierter Wartelistenplätze, Zuverlässigkeitsbewährung und wiederöffnete Auszahlungen.

Die vereinbarte Arbeitsweise bleibt: **keine manuellen Zwischen-Endtests durch den Auftraggeber**. Der gemeinsame manuelle Endtest erfolgt erst, wenn der vereinbarte Funktionsumfang als Ganzes bereit ist.

## Betrieb

Für zeitabhängige Funktionen muss der Laravel Scheduler regelmäßig ausgeführt werden. Er verarbeitet unter anderem:

- Nachweisfenster-Erinnerungen
- Start-/Nachweis-/Nachreichfristen
- Versandfristen
- Wartelistenreservierungen
- Rücksendefristen
- automatischen Auszahlungsschluss 24 Stunden nach bestätigter externer Zahlung

Für Browser-Push müssen gültige VAPID-Schlüssel in der Umgebung hinterlegt sein. Ohne Push bleiben Webapp- und E-Mail-Benachrichtigungen funktionsfähig.

## Installation / Update

`vendor/` wird nicht versioniert. Abhängigkeiten werden über Composer installiert. Nach neuen Commits sind insbesondere Migrationen und Laravel-Caches zu aktualisieren.

Alle Detailregeln ergeben sich ausschließlich aus [MASTERPROMPT.md](MASTERPROMPT.md).
