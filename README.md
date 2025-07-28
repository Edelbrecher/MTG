# MTG Collection Manager

Ein modernes Web-basiertes Magic: The Gathering Sammlungs- und Deck-Management-System für XAMPP.

## Features

### 🎯 Kernfunktionen
- **Benutzerregistrierung & -verwaltung** - Jeder Benutzer hat seinen eigenen abgetrennten Bereich
- **Kartensammlung verwalten** - Karten über API-Suche hinzufügen und verwalten
- **Deck-Builder** - Manuelle und automatische Deck-Erstellung nach Magic-Regeln
- **Admin-Bereich** - Vollständige Systemverwaltung und Benutzerübersicht
- **Moderne Benutzeroberfläche** - Responsives Design mit Magic-Farbschema

### 🃏 Kartenverwaltung
- Integration mit Scryfall API für Kartendaten
- Deutsche Übersetzung und Informationen
- Kartenbilder und vollständige Metadaten
- Farbcodierte Kartenrahmen entsprechend der Magic-Farben
- Quantitätsverwaltung und Zustandserfassung

### 🏗️ Deck-Builder
- Automatische Deck-Generierung basierend auf verfügbaren Karten
- Deck-Analyse mit Mana-Kurve und Typverteilung
- Format-spezifische Validierung (Standard, Modern, Commander, etc.)
- Sideboard-Unterstützung
- Visual Deck-Statistiken mit Charts

### 👨‍💼 Administration
- Benutzerverwaltung mit Admin-Rechten
- System-Statistiken und Überwachung
- Datenbank-Wartungstools
- Aktivitätsprotokolle

## Installation

### Voraussetzungen
- XAMPP mit Apache, MySQL und PHP 7.4+
- Internetverbindung für API-Zugriff

### Setup-Schritte

1. **Dateien kopieren**
   ```bash
   # Kopieren Sie alle Dateien in Ihr XAMPP htdocs Verzeichnis
   cp -r MTG/ /Applications/XAMPP/xamppfiles/htdocs/
   ```

2. **XAMPP starten**
   - Starten Sie Apache und MySQL über das XAMPP Control Panel

3. **Datenbank einrichten**
   - Die Datenbank wird automatisch beim ersten Zugriff erstellt
   - Alternativ können Sie das SQL-Skript manuell ausführen:
   ```sql
   mysql -u root < database/setup.sql
   ```

4. **Erste Schritte**
   - Öffnen Sie `http://localhost/MTG` in Ihrem Browser
   - Registrieren Sie sich oder nutzen Sie den Standard-Admin:
     - **Email:** admin@example.com
     - **Passwort:** admin123

## Projektstruktur

```
MTG/
├── assets/
│   ├── css/
│   │   └── style.css          # Haupt-Stylesheet mit MTG-Farben
│   └── js/
│       └── app.js             # JavaScript-Funktionen und API
├── auth/
│   ├── login.php              # Anmelde-Logic
│   ├── register.php           # Registrierungs-Logic
│   └── logout.php             # Abmelde-Logic
├── admin/
│   └── index.php              # Admin-Dashboard
├── config/
│   └── database.php           # Datenbank-Konfiguration
├── includes/
│   ├── navbar.php             # Navigation
│   └── footer.php             # Footer
├── database/
│   └── setup.sql              # Datenbank-Schema
├── index.php                  # Startseite mit Login
├── dashboard.php              # Benutzer-Dashboard
├── collection.php             # Sammlungsübersicht
├── decks.php                  # Deck-Verwaltung
├── deck_view.php              # Einzelne Deck-Ansicht
├── settings.php               # Benutzereinstellungen
└── README.md                  # Diese Datei
```

## Datenbank-Schema

### Haupttabellen
- **users** - Benutzerkonten und Admin-Rechte
- **collections** - Kartensammlungen pro Benutzer
- **decks** - Deck-Definitionen
- **deck_cards** - Karten in Decks (inkl. Sideboard)
- **user_settings** - Individuelle Benutzereinstellungen
- **card_cache** - API-Cache für Kartendaten

## Verwendung

### Karten hinzufügen
1. Gehen Sie zu "Sammlung"
2. Geben Sie den exakten Kartennamen ein
3. Die Karte wird automatisch über die Scryfall API geladen
4. Kartenbilder und alle relevanten Daten werden gespeichert

### Decks erstellen
1. **Manuell:** Fügen Sie Karten einzeln hinzu
2. **Automatisch:** Lassen Sie das System ein Deck aus Ihrer Sammlung generieren
3. Analysieren Sie Ihr Deck mit den eingebauten Tools

### Filter und Suche
- Filtern nach Farben, Typen und Namen
- Sortierung nach verschiedenen Kriterien
- Schnellsuche durch die gesamte Sammlung

## Technische Details

### API-Integration
- **Scryfall API** für Kartendaten und -bilder
- Automatisches Caching zur Performance-Optimierung
- Fehlerbehandlung für nicht gefundene Karten

### Design-Prinzipien
- **Mobile-First** responsive Design
- **Magic-Farbschema** mit originalgetreuen Mana-Farben
- **Moderne UI-Komponenten** mit CSS Grid und Flexbox

### Sicherheit
- Passwort-Hashing mit PHP's `password_hash()`
- SQL-Injection-Schutz durch Prepared Statements
- Session-Management für Benutzerauthentifizierung
- Admin-Bereich mit Rollenberechtigung

## Anpassungen

### Styling
Die Datei `assets/css/style.css` enthält alle Styles mit CSS-Variablen für einfache Anpassungen:

```css
:root {
    --primary-color: #2563eb;
    --white-mana: #fffbd5;
    --blue-mana: #0e68ab;
    --black-mana: #150b00;
    --red-mana: #d3202a;
    --green-mana: #00733e;
}
```

### Datenbank-Konfiguration
Passen Sie `config/database.php` für andere Datenbankeinstellungen an:

```php
$host = 'localhost';
$dbname = 'mtg_collection';
$username = 'root';
$password = '';
```

## Bekannte Limitierungen

- Scryfall API hat Rate-Limits (normalerweise kein Problem bei normalem Gebrauch)
- Kartensuche erfordert exakte Namen (Fuzzy-Search in Entwicklung)
- Offline-Betrieb nicht möglich (API-abhängig)

## Zukünftige Features

- [ ] Import/Export von Deck-Listen
- [ ] Preisintegration für Sammlungswert
- [ ] Tournament-Management
- [ ] Social Features (Deck-Sharing)
- [ ] Mobile App
- [ ] Mehrsprachige Unterstützung

## Support

Bei Problemen oder Fragen:
1. Überprüfen Sie die XAMPP-Logs
2. Stellen Sie sicher, dass alle PHP-Extensions aktiv sind
3. Kontrollieren Sie die Datenbankverbindung

## Lizenz

Dieses Projekt ist für private und Bildungszwecke kostenlos verwendbar.
Magic: The Gathering ist ein Warenzeichen von Wizards of the Coast.
