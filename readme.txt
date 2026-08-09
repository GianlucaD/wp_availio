=== Dashboard for Availio ===
Contributors: gianlucad
Tags: booking, tennis, reservations, availio, fairgate
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show your club's court bookings from Availio (Fairgate Standard API) as a dashboard with a date picker. Embed via the [availio_dashboard] shortcode.

== Description ==

Dashboard for Availio embeds your club's court occupancy from **Availio** (the booking platform by Fairgate) directly into WordPress. The data is fetched server-side through the official **Fairgate Standard API (FSA)** and rendered as a clear, TV-friendly occupancy grid of all your courts, including a date picker to browse the coming days.

The API token stays exclusively on the server; it is never sent to the browser.

**Features**

* Occupancy grid of all courts for the selected day (free / booked / in progress)
* Date picker with previous/next navigation
* Automatic refresh at a configurable interval
* Configurable display range, slot size and time zone
* Customizable color scheme (color pickers in the settings)
* Optional court filter (show only selected courts)
* Embed via the `[availio_dashboard]` shortcode
* Token kept server-side, no credentials in the HTML/JS

Please note: the Fairgate Standard API returns reservations as anonymous time slots (without player names). The dashboard therefore shows the occupancy, not the names of the people who booked.

This plugin is an unofficial, community-developed project and is not affiliated with Fairgate AG. "Availio" and "Fairgate" are trademarks of their respective owners.

== External services ==

This plugin connects to the Fairgate Standard API (FSA), operated by Fairgate AG, to retrieve and display your club's court occupancy. Without this connection the dashboard cannot show any data.

* Provider: Fairgate AG (default base URL: https://fsa.fairgate.ch).
* Purpose: retrieving the reservations and the court list for display.
* When: server-side on every request to a page containing the shortcode, and on the auto-refresh / AJAX requests.
* Data transmitted: your configured Organisation ID (OID) and your API token (for authentication), plus the requested time range or date. NO data about your website visitors is transmitted to the service.

Terms of service: https://fairgate.ch/agb-standard/
Privacy policy: https://fairgate.ch/datenschutzerklarung/

== Installation ==

1. Upload the plugin as a ZIP via *Plugins → Add New → Upload Plugin* and activate it (or copy the `dashboard-for-availio` folder to `wp-content/plugins/`).
2. Go to *Settings → Dashboard for Availio* and enter your credentials:
   * **Organisation ID (OID)** of your club
   * **API token** from your Availio settings
3. Optionally adjust the display options (range, slot size, auto-refresh, colors, court filter).
4. Add the shortcode `[availio_dashboard]` to a page, post or widget.

You obtain API access (OID and token) through your Availio/Fairgate contract; the FSA must be enabled for your organisation.

**Important – choose the right authentication method:** in Availio, under "Authentication methods", select the **specific short API token** (long-lived, no expiry). The JWT option expires automatically, which makes the dashboard stop working until you renew the token. The specific API token is set up by Fairgate support on request.

== Frequently Asked Questions ==

= Where do I get the OID and the API token? =
Both come from the Availio/Fairgate settings of your organisation. The Fairgate Standard API (FSA) must be booked/enabled for your account.

= Which authentication method should I choose in Availio? =
Choose the **specific short API token** (long-lived, no expiry), not the JWT option. A JWT expires automatically, so the dashboard stops working until you enter a new token. The specific API token is set up by Fairgate support on request.

= My dashboard shows "token invalid or expired (HTTP 401)" =
Your token has expired or is no longer valid. Enter a new token in the settings. To avoid this happening again, switch to the specific short API token in Availio (see above). The plugin also shows an admin notice before a token expires.

= Are the names of the people who booked shown? =
No. The official FSA returns reservations as anonymous time slots. The dashboard therefore only shows when a court is occupied, not by whom.

= Is the token secure? =
Yes. The token is kept server-side and is only used for the API request. The delivered HTML contains only the finished occupancy tiles.

= Can I store the credentials outside the database? =
Yes. Define the values as constants in your `wp-config.php` and they will be used instead of the database settings:

`define('AVAILIO_OID', 'your-oid');`
`define('AVAILIO_ACCESS_KEY', 'your-token');`
`define('AVAILIO_BASE_URL', 'https://fsa.fairgate.ch'); // optional`

When a constant is set, the corresponding field on the settings page is shown as read-only.

= Can I show only certain courts? =
Yes, via the court filter in the settings (comma-separated court IDs).

== Screenshots ==

1. Occupancy grid with date picker in the frontend.
2. Settings page with API access and display options.

== Changelog ==

= 1.2.0 =
* New: admin notice warns before the API token expires (and when it has expired), read from the token's expiry date.
* Docs: recommend the specific short API token (long-lived) instead of JWT in Availio's authentication settings.

= 1.1.3 =
* WordPress.org preparation: external services disclosure, security hardening, plugin name "Dashboard for Availio", English readme.

= 1.1.2 =
* New: the club name is shown as a title above the dashboard (when set).

= 1.1.1 =
* Fix: date picker arrows and tabs now follow the color scheme (theme override prevented).

= 1.1.0 =
* New: customizable color scheme (color pickers in the settings).
* New: ready-made color presets (one click).
* New: credentials optionally via wp-config.php constants.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.2.0 =
Adds an admin warning before the API token expires.

= 1.1.3 =
Prepared for the WordPress.org directory (English readme, security hardening).
