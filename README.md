# Zeitwerk – Zeiterfassung & Abrechnung (PHP/MySQL/Bootstrap 5)

Minimaler Projekt-Start für:
- Login/Registrierung (Session-basiert)
- Mandantentrennung per `account_id`
- Firmen, Projekte, Aufgaben (rudimentär)
- Zeiten: Start/Stop (inkl. "Globaler Start" ohne Aufgabe)
- Einfaches Dashboard
- Bootstrap 5 Layout

> **Hinweis:** Dies ist ein bewusst kleines Grundgerüst (MVP). Viele Features sind als TODO markiert.

## Setup

1. Erstellen Sie eine MySQL-Datenbank (UTF8MB4).
2. Importieren Sie `schema.sql`.
3. Kopieren Sie `config.example.php` nach `config.php` und passen Sie Zugangsdaten an.
4. Starten Sie den PHP-Server im `public/`-Ordner:
   ```bash
   php -S localhost:8000 -t public
   ```
5. Registrieren Sie zuerst einen Benutzer unter `/register.php` und melden Sie sich an.

## Ordnerstruktur

```
zeitwerk/
├─ public/              # Öffentl. Root (Router + Seiten)
├─ src/                 # PHP-Quellen (Auth, DB, Layout, Utils)
├─ schema.sql           # Datenbankschema
├─ config.example.php   # Muster-Konfiguration
└─ README.md
```

## Sicherheit (MVP)
- CSRF-Schutz bei Formularen (`src/csrf.php`)
- Passwort-Hashing via `password_hash`
- Mandantentrennung: ALLE SELECT/INSERT/UPDATE müssen `account_id` (und/oder `user_id`) filtern
- TODO: Hardening (Content Security Policy, strikte Input-Validierung, Rate Limiting, Audit-Logs)

## Nächste Schritte (Vorschläge)
- CRUD komplettieren (Bearbeiten/Löschen) für alle Entitäten
- Angebots-Workflow (Statuswechsel → Aufgaben werden „offen“)
- Rechnungsentwurf aus nicht abgerechneten Zeiten (Drag & Drop Zusammenfassung)
- XML-Export der Rechnung
- Kalenderansicht (z.B. FullCalendar) mit manueller Positionierung
- Stripe-Integration (Monatsgebühren), Rollen & Team-Accounts
- Tests & Migrations (Phinx o.ä.)
