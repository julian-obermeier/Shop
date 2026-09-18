# Wear&Earn – Laravel 13 Starter

Produktionsorientierter Entwicklungsstand der Reverse-Shop-/Ankaufsplattform.

> **Verbindliche Projektspezifikation:** Die fachliche Zieldefinition steht in [MASTERPROMPT.md](MASTERPROMPT.md). Bei Widersprüchen zwischen bestehendem Code, diesem README und dem Masterprompt gilt der Masterprompt. Der aktuelle Code enthält noch ältere Funktionen, die schrittweise an die neue Spezifikation angepasst werden müssen.

## Aktuell im Code enthalten

- Registrierung / Login mit 18+-Prüfung
- geschützter Adminbereich
- Kategorien, Angebote und dynamische Zusatzoptionen
- Live-Vergütungsrechner im Frontend
- serverseitige Preisberechnung
- Angebotssnapshot je Auftrag
- Auftragstage und tägliche Nachweispflichten
- private Foto-Speicherung außerhalb von `/public`
- SHA-256-Hash pro Nachweisdatei
- Admin-Prüfung von Nachweisen
- Wallet-/Ledger-Grundlage
- Auszahlungsanträge
- Admin-Angebotseditor
- Auftragsstatushistorie
- Versand-/Audit-Datenmodell
- responsive UI

Einige bestehende Module entsprechen noch nicht der verbindlichen Zieldefinition, insbesondere Admin-2FA, mehrere Adminrollen, Identitätsprüfung, allgemeine Nachrichten, Mindest-Auszahlungsbetrag, Dateiaufbewahrungs-/Purge-Logik und einzelne Profil-/Auszahlungsabläufe. Die vollständige Soll-Liste steht im Abschnitt „Bekannte Abweichungen des aktuellen Codes“ in `MASTERPROMPT.md`.

## Technische Basis

- PHP 8.3+
- Laravel 13
- MySQL / MariaDB
- Blade + Vanilla CSS/JS, daher kein Node/Vite-Zwang für die aktuelle Oberfläche
- Datenbank-Queue und Datenbank-Cache möglich
- privater Storage für Nachweise

## Teststrategie

Zwischenstände werden nicht vom Nutzer getestet. Erst nach Umsetzung des vollständig vereinbarten Funktionsumfangs erfolgt der gemeinsame Endtest. Während der Entwicklung werden automatisierte Tests verwendet und erweitert.

## Projektumfang

Die Plattform ist ein Reverse-Shop / eine Ankaufsplattform. Registrierte volljährige Anbieterinnen können vom Betreiber erstellte Ankaufangebote auswählen, Zusatzoptionen konfigurieren, mehrtägige Aufträge mit Nachweisen erfüllen, Ware versenden und nach Wareneingang sowie Prüfung eine Vergütung erhalten.

Zum Zielumfang gehören insbesondere:

- Anbieterinnenbereich
- ein Adminbereich
- Angebotseditor
- Aufträge und Terminlogik
- Nachweise und Nachweisprüfung
- Socken-Trageauftragslogik
- Wartelisten
- Versand und Wareneingang
- Waren-/Qualitätsprüfung
- Wallet und Auszahlungen
- auftragsbezogene Nachrichten
- Einwilligungen/Dokumente
- Zuverlässigkeitsregeln
- Audit-Log
- Benachrichtigungen

Alle Detailregeln ergeben sich ausschließlich aus `MASTERPROMPT.md`.

## Hinweis

`vendor/` wird nicht versioniert. Die Installation erfolgt über Composer.
