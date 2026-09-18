# Wear&Earn – Laravel 13 Starter

Produktionsorientierter erster Entwicklungsstand der Reverse-Shop-/Ankaufsplattform.

## Enthalten

- Registrierung / Login mit 18+-Prüfung
- Rollen-Grundlage und geschützter Adminbereich
- Kategorien, Angebote und dynamische Zusatzoptionen
- Live-Vergütungsrechner im Frontend
- serverseitige Preisberechnung
- unveränderbarer Angebotssnapshot je Auftrag
- Auftragstage und tägliche Nachweispflichten
- private Foto-Uploads außerhalb von `/public`
- SHA-256-Hash pro Nachweisdatei
- Admin-Prüfung von Nachweisen
- Wallet-Ledger mit `pending` / `available`
- Auszahlungsanträge
- Admin-Angebotseditor
- Auftragsstatushistorie
- Versand-/Audit-Datenmodell vorbereitet
- responsive UI im Stil des zuvor erstellten Screenshots

## Technische Basis

- PHP 8.3+
- Laravel 13
- MySQL / MariaDB
- Blade + Vanilla CSS/JS, daher kein Node/Vite-Zwang für die aktuelle Oberfläche
- Datenbank-Queue und Datenbank-Cache möglich
- privater Storage für Nachweise

## Installation

1. Dateien auf den Server laden.
2. Composer-Abhängigkeiten installieren:
   `composer install --no-dev --optimize-autoloader`
3. `.env.example` nach `.env` kopieren und Datenbank-/Mailzugang eintragen.
4. `php artisan key:generate`
5. `php artisan migrate --seed`
6. DocumentRoot der Domain auf `/public` setzen.
7. Schreibrechte für `storage` und `bootstrap/cache` sicherstellen.
8. Cronjob minütlich einrichten:
   `php /PFAD/ZUM/PROJEKT/artisan schedule:run`

Die Anwendung braucht für den aktuellen Stand weder Redis noch einen dauerhaft laufenden WebSocket-Server.

## Teststrategie

Zwischenstände werden nicht vom Nutzer getestet. Erst nach Umsetzung des vollständig vereinbarten Funktionsumfangs erfolgt der gemeinsame Endtest.

## Projektumfang

Die Plattform ist ein Reverse-Shop / eine Ankaufsplattform. Registrierte volljährige Anbieterinnen können vom Betreiber erstellte Ankaufangebote auswählen, Zusatzoptionen konfigurieren, mehrtägige Aufträge mit täglichen Nachweisen erfüllen, Ware versenden und nach Wareneingang sowie Prüfung eine Vergütung freigeschaltet bekommen. Das System umfasst Anbieterinnenbereich, Adminbereich, Angebotseditor, Aufträge, Nachweise, Versand, Wareneingang, Wallet/Ledger, Auszahlungen, Nachrichten, Dokument-/Einwilligungsverwaltung, Audit-Log und Sicherheitsfunktionen.

## Hinweis

`vendor/` wird nicht versioniert. Die Installation erfolgt über Composer.