=== Availio Dashboard ===
Contributors: gianlucad
Tags: booking, tennis, reservations, availio, fairgate
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zeigt Platz-Buchungen aus Availio (Fairgate Standard-API) als Dashboard mit Datepicker. Einbindung per Shortcode [availio_dashboard].

== Description ==

Availio Dashboard bindet die Platzbelegung deines Vereins aus **Availio** (der Buchungsplattform von Fairgate) direkt in WordPress ein. Die Daten werden serverseitig über die offizielle **Fairgate Standard-API (FSA)** geholt und als übersichtliches, TV-taugliches Belegungsraster aller Plätze dargestellt – inklusive Datepicker zum Blättern durch die nächsten Tage.

Der API-Token bleibt dabei ausschliesslich auf dem Server; er wird nie an den Browser ausgeliefert.

**Funktionen**

* Belegungsraster aller Plätze für den gewählten Tag (frei / belegt / laufend)
* Datepicker mit Vor- und Zurück-Navigation
* Automatische Aktualisierung in einstellbarem Intervall
* Anpassbares Farbschema über Farbwähler in den Einstellungen
* Konfigurierbarer Anzeigezeitraum, Slot-Raster und Zeitzone
* Optionaler Platz-Filter (nur bestimmte Plätze anzeigen)
* Einbindung per Shortcode `[availio_dashboard]`
* Token serverseitig gehalten – keine Zugangsdaten im HTML/JS

**Hinweis:** Die Fairgate Standard-API liefert Reservationen als anonyme Zeitfenster (ohne Spielernamen). Das Dashboard zeigt entsprechend die Belegung, nicht die Namen der Bucher.

Dieses Plugin ist ein inoffizielles, von der Community entwickeltes Projekt und steht in keiner Verbindung zu Fairgate AG. „Availio“ und „Fairgate“ sind Marken ihrer jeweiligen Inhaber.

== Installation ==

1. Das Plugin über *Plugins → Installieren → Plugin hochladen* als ZIP hochladen und aktivieren (oder den Ordner `availio-dashboard` nach `wp-content/plugins/` kopieren).
2. Unter *Einstellungen → Availio Dashboard* die Zugangsdaten eintragen:
   * **Organisations-ID (OID)** deines Clubs
   * **API-Key (Bearer/JWT-Token)** aus den Availio-Einstellungen
3. Optional Anzeige-Optionen anpassen (Zeitraum, Slot-Raster, Auto-Refresh, Platz-Filter).
4. Den Shortcode `[availio_dashboard]` auf einer Seite, in einem Beitrag oder Widget einfügen.

Einen API-Zugang (OID und Token) erhältst du über deinen Availio-/Fairgate-Vertrag; die FSA muss für deine Organisation freigeschaltet sein.

== Frequently Asked Questions ==

= Woher bekomme ich die OID und den API-Token? =
Beides stammt aus den Availio-/Fairgate-Einstellungen deiner Organisation. Die Fairgate Standard-API (FSA) muss für deinen Account gebucht/freigeschaltet sein.

= Werden die Namen der Bucher angezeigt? =
Nein. Die offizielle FSA liefert Reservationen als anonyme Zeitfenster. Das Dashboard zeigt daher nur, wann ein Platz belegt ist, nicht von wem.

= Ist der Token sicher? =
Ja. Der Token wird serverseitig gehalten und ausschliesslich für den API-Aufruf verwendet. Das ausgelieferte HTML enthält nur die fertigen Belegungs-Kacheln.

= Kann ich die Zugangsdaten ausserhalb der Datenbank speichern? =
Ja. Definiere die Werte als Konstanten in deiner `wp-config.php`, dann werden sie statt der Datenbank-Einstellungen verwendet:

`define('AVAILIO_OID', 'deine-oid');`
`define('AVAILIO_ACCESS_KEY', 'dein-token');`
`define('AVAILIO_BASE_URL', 'https://fsa.fairgate.ch'); // optional`

Ist eine Konstante gesetzt, wird das entsprechende Feld auf der Einstellungsseite als „nur lesend" angezeigt.

= Kann ich nur bestimmte Plätze anzeigen? =
Ja, über den Platz-Filter in den Einstellungen (kommagetrennte Platz-IDs).

== Screenshots ==

1. Belegungsraster mit Datepicker im Frontend.
2. Einstellungsseite mit API-Zugang und Anzeige-Optionen.

== Changelog ==

= 1.1.2 =
* Neu: Club-Name wird als Titel über dem Dashboard angezeigt (wenn gesetzt).

= 1.1.1 =
* Fix: Datepicker-Pfeile und Tabs folgen jetzt dem Farbschema (Theme-Übersteuerung verhindert).

= 1.1.0 =
* Neu: Konfigurierbares Farbschema (Farbwähler in den Einstellungen).
* Neu: Fertige Farb-Voreinstellungen (Blau, Grün, Ziegel, Grau, Dunkel) per Klick.
* Neu: Zugangsdaten optional über wp-config.php-Konstanten.

= 1.0.0 =
* Erste öffentliche Version.

== Upgrade Notice ==

= 1.1.2 =
Der Club-Name erscheint jetzt als Titel über dem Dashboard.

= 1.1.1 =
Behebt blau bleibende Datepicker-Pfeile trotz eigenem Farbschema.

= 1.1.0 =
Farbschema jetzt in den Einstellungen anpassbar.

= 1.0.0 =
Erste öffentliche Version.
