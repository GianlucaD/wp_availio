# Dashboard for Availio

[![Release](https://img.shields.io/github/v/release/GianlucaD/wp_availio)](https://github.com/GianlucaD/wp_availio/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/GianlucaD/wp_availio/total)](https://github.com/GianlucaD/wp_availio/releases/latest)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)

**➜ [Neueste Version herunterladen (dashboard-for-availio.zip)](https://github.com/GianlucaD/wp_availio/releases/latest/download/dashboard-for-availio.zip)**

WordPress-Plugin, das die Platzbelegung deines Vereins aus **Availio** (Fairgate) direkt in WordPress einbindet. Die Daten kommen serverseitig über die offizielle **Fairgate Standard-API (FSA)** und werden als übersichtliches, TV-taugliches Belegungsraster mit Datepicker dargestellt. Einbindung per Shortcode `[availio_dashboard]`.

Der API-Token bleibt ausschliesslich auf dem Server – er wird nie an den Browser ausgeliefert.

## Funktionen

- Belegungsraster aller Plätze für den gewählten Tag (frei / belegt / laufend)
- Datepicker mit Vor-/Zurück-Navigation
- Automatische Aktualisierung in einstellbarem Intervall
- Konfigurierbarer Anzeigezeitraum, Slot-Raster und Zeitzone
- Anpassbares Farbschema (Farbwähler in den Einstellungen)
- Optionaler Platz-Filter
- Shortcode `[availio_dashboard]`

> **Hinweis:** Die FSA liefert Reservationen als anonyme Zeitfenster (ohne Spielernamen). Das Dashboard zeigt daher die Belegung, nicht die Namen der Bucher.

## Installation

1. [`dashboard-for-availio.zip` der neuesten Version herunterladen](https://github.com/GianlucaD/wp_availio/releases/latest/download/dashboard-for-availio.zip) (oder das Repo klonen und den Ordner nach `wp-content/plugins/dashboard-for-availio/` kopieren).
2. Plugin in WordPress aktivieren.
3. Unter **Einstellungen → Dashboard for Availio** eintragen:
   - Organisations-ID (OID) deines Clubs
   - API-Key (Bearer/JWT-Token) aus den Availio-Einstellungen
4. Optional die Anzeige-Optionen anpassen.
5. Shortcode `[availio_dashboard]` auf einer Seite oder in einem Widget einfügen.

Einen API-Zugang (OID und Token) erhältst du über deinen Availio-/Fairgate-Vertrag; die FSA muss für deine Organisation freigeschaltet sein.

## Konfiguration

| Einstellung        | Beschreibung                                             |
|--------------------|---------------------------------------------------------|
| Organisations-ID   | OID deines Clubs in Availio                              |
| API-Key            | JWT-/Bearer-Token aus den Availio-Einstellungen          |
| API-Basis-URL      | Standard: `https://fsa.fairgate.ch`                      |
| Club-Name          | Anzeige in der Kopfzeile                                 |
| Zeitzone           | z. B. `Europe/Zurich`                                    |
| Anzeigezeit        | Erste/letzte angezeigte Stunde                           |
| Slot-Raster        | Granularität der Zeitslots in Minuten                    |
| Auto-Refresh       | Aktualisierungsintervall in Sekunden                     |
| Platz-Filter       | Kommagetrennte Platz-IDs (leer = alle)                   |

## Zugangsdaten über wp-config.php (optional)

Wer Secrets nicht in der Datenbank halten will, kann OID und Token als Konstanten in der `wp-config.php` definieren. Sie haben Vorrang vor den Einstellungen, und das jeweilige Feld wird im Admin nur lesend angezeigt.

```php
define('AVAILIO_OID', 'deine-oid');
define('AVAILIO_ACCESS_KEY', 'dein-token');
define('AVAILIO_BASE_URL', 'https://fsa.fairgate.ch'); // optional
```

## Sicherheit & Datenschutz

- Der API-Token wird serverseitig gehalten und nie im Frontend ausgegeben.
- Es werden keine personenbezogenen Daten angezeigt – die FSA liefert nur anonyme Belegungszeiten.
- Zugangsdaten können wahlweise in der Datenbank (Einstellungsseite) oder in der `wp-config.php` (Konstanten) liegen.

## Anforderungen

- WordPress 6.0+
- PHP 8.0+
- Ein Availio-/Fairgate-Account mit freigeschalteter FSA

## Lizenz

GPL-2.0-or-later. Siehe [LICENSE](LICENSE).

## Haftungsausschluss

Inoffizielles, von der Community entwickeltes Projekt. Keine Verbindung zu Fairgate AG. „Availio“ und „Fairgate“ sind Marken ihrer jeweiligen Inhaber.
