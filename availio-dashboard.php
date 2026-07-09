<?php
/**
 * Plugin Name:       Availio Dashboard
 * Plugin URI:        https://github.com/GianlucaD/wp_availio
 * Description:       Zeigt Platz-Buchungen aus Availio (Fairgate Standard-API / FSA) als interaktives Dashboard mit Datepicker. Einbindung via Shortcode [availio_dashboard].
 * Version:           1.0.0
 * Author:            GianlucaD
 * Author URI:        https://github.com/GianlucaD
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       availio-dashboard
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AVAILIO_DASH_VERSION', '1.0.0');
define('AVAILIO_DASH_DIR',     plugin_dir_path(__FILE__));
define('AVAILIO_DASH_URL',     plugin_dir_url(__FILE__));

require_once AVAILIO_DASH_DIR . 'includes/availio-api.php';

// ── AJAX: Tages-Daten für den Datepicker ──────────────────────────────────────
add_action('wp_ajax_availio_day',        'availio_dash_ajax_day');
add_action('wp_ajax_nopriv_availio_day', 'availio_dash_ajax_day');

function availio_dash_ajax_day(): void
{
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'availio_day')) {
        wp_send_json_error('Ungültige Anfrage.', 403);
        return;
    }

    $date = sanitize_text_field($_POST['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_send_json_error('Ungültiges Datum.');
        return;
    }

    $opts = availio_dash_get_options();
    if (empty($opts['oid']) || empty($opts['access_key'])) {
        wp_send_json_error('API nicht konfiguriert.');
        return;
    }

    $tz  = new DateTimeZone($opts['time_zone']);
    $now = time();

    $reqDay = DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
    if (!$reqDay) {
        wp_send_json_error('Ungültiges Datum.');
        return;
    }

    $startOfDay = $reqDay->setTime((int) $opts['day_start_hour'], 0);
    $endOfDay   = $reqDay->setTime((int) $opts['day_end_hour'], 0);

    // Stunden-Fenster relativ zu jetzt berechnen (API-Limit: 72 h)
    $nextHours     = min(72, max(1, (int) ceil(($endOfDay->getTimestamp() - $now) / 3600) + 1));
    $previousHours = max(0, (int) ceil(($now - $startOfDay->getTimestamp()) / 3600) + 1);

    $court_filter = array_values(array_filter(
        array_map('trim', explode(',', (string) $opts['court_filter']))
    ));

    $cfg = [
        'availio_base_url'   => $opts['base_url'],
        'availio_oid'        => $opts['oid'],
        'availio_access_key' => $opts['access_key'],
        'time_zone'          => $opts['time_zone'],
        'next_hours'         => $nextHours,
        'previous_hours'     => $previousHours,
        'day_start_hour'     => (int) $opts['day_start_hour'],
        'day_end_hour'       => (int) $opts['day_end_hour'],
        'slot_minutes'       => (int) $opts['slot_minutes'],
        'refresh_seconds'    => (int) $opts['refresh_seconds'],
        'http_timeout'       => 8,
        'court_filter'       => $court_filter,
    ];

    $api = availio_fetch($cfg);
    if (!$api['ok']) {
        wp_send_json_error('API-Fehler: ' . $api['error']);
        return;
    }

    // currentTimestamp auf Mittag des gewünschten Tags setzen,
    // damit availio_build_day_grid den richtigen Tag aufbaut.
    $api['currentTimestamp'] = $reqDay->setTime(12, 0)->getTimestamp();

    $tz   = new DateTimeZone($api['timeZone']);
    $grid = availio_build_day_grid($api, $cfg, $tz);

    ob_start();
    availio_render_day_grid($api, $grid);
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}

// ── Admin-Menü ────────────────────────────────────────────────────────────────
add_action('admin_menu', function (): void {
    add_options_page(
        'Availio Dashboard',
        'Availio Dashboard',
        'manage_options',
        'availio-dashboard',
        'availio_dash_settings_page'
    );
});

// ── Einstellungen registrieren ────────────────────────────────────────────────
add_action('admin_init', function (): void {
    register_setting('availio_dash', 'availio_dash_options', [
        'sanitize_callback' => 'availio_dash_sanitize_options',
    ]);
});

function availio_dash_get_options(): array
{
    $defaults = [
        'base_url'        => 'https://fsa.fairgate.ch',
        'oid'             => '',
        'access_key'      => '',
        'club_name'       => '',
        'time_zone'       => 'Europe/Zurich',
        'day_start_hour'  => 7,
        'day_end_hour'    => 23,
        'slot_minutes'    => 30,
        'refresh_seconds' => 60,
        'court_filter'    => '',
    ];
    $opts = wp_parse_args(get_option('availio_dash_options', []), $defaults);

    // Optional: Zugangsdaten aus wp-config.php-Konstanten übersteuern.
    // Wer Secrets nicht in der Datenbank halten will, definiert z.B.:
    //   define('AVAILIO_ACCESS_KEY', '…'); define('AVAILIO_OID', '…');
    if (defined('AVAILIO_OID') && AVAILIO_OID !== '') {
        $opts['oid'] = (string) AVAILIO_OID;
    }
    if (defined('AVAILIO_ACCESS_KEY') && AVAILIO_ACCESS_KEY !== '') {
        $opts['access_key'] = (string) AVAILIO_ACCESS_KEY;
    }
    if (defined('AVAILIO_BASE_URL') && AVAILIO_BASE_URL !== '') {
        $opts['base_url'] = (string) AVAILIO_BASE_URL;
    }

    return $opts;
}

/** Prüft, ob ein Feld per wp-config.php-Konstante gesetzt ist (dann Anzeige nur lesend). */
function availio_dash_const_active(string $field): bool
{
    switch ($field) {
        case 'oid':        return defined('AVAILIO_OID') && AVAILIO_OID !== '';
        case 'access_key': return defined('AVAILIO_ACCESS_KEY') && AVAILIO_ACCESS_KEY !== '';
        case 'base_url':   return defined('AVAILIO_BASE_URL') && AVAILIO_BASE_URL !== '';
    }
    return false;
}

function availio_dash_sanitize_options(array $input): array
{
    return [
        'base_url'        => esc_url_raw($input['base_url'] ?? 'https://fsa.fairgate.ch'),
        'oid'             => sanitize_text_field($input['oid'] ?? ''),
        'access_key'      => sanitize_text_field($input['access_key'] ?? ''),
        'club_name'       => sanitize_text_field($input['club_name'] ?? ''),
        'time_zone'       => sanitize_text_field($input['time_zone'] ?? 'Europe/Zurich'),
        'day_start_hour'  => max(0, min(23, (int) ($input['day_start_hour'] ?? 7))),
        'day_end_hour'    => max(1, min(24, (int) ($input['day_end_hour'] ?? 23))),
        'slot_minutes'    => max(15, min(60, (int) ($input['slot_minutes'] ?? 30))),
        'refresh_seconds' => max(30, min(600, (int) ($input['refresh_seconds'] ?? 60))),
        'court_filter'    => sanitize_text_field($input['court_filter'] ?? ''),
    ];
}

// ── Einstellungs-Seite ────────────────────────────────────────────────────────
function availio_dash_settings_page(): void
{
    $opts = availio_dash_get_options();
    ?>
    <div class="wrap">
        <h1>Availio Dashboard – Einstellungen</h1>
        <p>Konfiguriere hier die Verbindung zur Availio (Fairgate FSA) API und die Anzeige-Optionen.</p>

        <form method="post" action="options.php">
            <?php settings_fields('availio_dash'); ?>

            <h2>API-Zugang</h2>
            <?php
            $oidConst  = availio_dash_const_active('oid');
            $keyConst  = availio_dash_const_active('access_key');
            $baseConst = availio_dash_const_active('base_url');
            if ($oidConst || $keyConst || $baseConst): ?>
                <p class="description" style="max-width:40em;">
                    Ein oder mehrere Werte sind über Konstanten in der
                    <code>wp-config.php</code> gesetzt und lassen sich hier nicht
                    ändern (empfohlen, wenn du Secrets nicht in der Datenbank halten willst).
                </p>
            <?php endif; ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ad_oid">Organisations-ID (OID)</label></th>
                    <td>
                        <input type="text" id="ad_oid"
                               name="availio_dash_options[oid]"
                               value="<?= esc_attr($opts['oid']) ?>"
                               class="regular-text" autocomplete="off"
                               <?= $oidConst ? 'readonly' : '' ?>>
                        <p class="description">
                            Die OID deines Clubs in der Availio-URL.
                            <?= $oidConst ? '<strong>Per <code>AVAILIO_OID</code> in wp-config.php gesetzt.</strong>' : '' ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_key">API-Key (Bearer Token)</label></th>
                    <td>
                        <input type="password" id="ad_key"
                               name="availio_dash_options[access_key]"
                               value="<?= $keyConst ? '' : esc_attr($opts['access_key']) ?>"
                               class="regular-text" autocomplete="off"
                               <?= $keyConst ? 'readonly placeholder="(über wp-config.php gesetzt)"' : '' ?>>
                        <p class="description">
                            JWT Bearer-Token aus den Availio-Einstellungen.
                            <?= $keyConst ? '<strong>Per <code>AVAILIO_ACCESS_KEY</code> in wp-config.php gesetzt.</strong>' : '' ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_base">API-Basis-URL</label></th>
                    <td>
                        <input type="url" id="ad_base"
                               name="availio_dash_options[base_url]"
                               value="<?= esc_attr($opts['base_url']) ?>"
                               class="regular-text"
                               <?= $baseConst ? 'readonly' : '' ?>>
                        <p class="description">
                            Standard: <code>https://fsa.fairgate.ch</code>
                            <?= $baseConst ? '<strong>Per <code>AVAILIO_BASE_URL</code> in wp-config.php gesetzt.</strong>' : '' ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2>Anzeige-Optionen</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ad_club">Club-Name</label></th>
                    <td>
                        <input type="text" id="ad_club"
                               name="availio_dash_options[club_name]"
                               value="<?= esc_attr($opts['club_name']) ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_tz">Zeitzone</label></th>
                    <td>
                        <input type="text" id="ad_tz"
                               name="availio_dash_options[time_zone]"
                               value="<?= esc_attr($opts['time_zone']) ?>"
                               class="regular-text">
                        <p class="description">z.B. <code>Europe/Zurich</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Anzeigezeit</th>
                    <td>
                        <label>
                            Von Stunde:
                            <input type="number"
                                   name="availio_dash_options[day_start_hour]"
                                   value="<?= (int) $opts['day_start_hour'] ?>"
                                   min="0" max="23" style="width:4.5em">
                        </label>
                        &ensp;
                        <label>
                            Bis Stunde:
                            <input type="number"
                                   name="availio_dash_options[day_end_hour]"
                                   value="<?= (int) $opts['day_end_hour'] ?>"
                                   min="1" max="24" style="width:4.5em">
                        </label>
                        <p class="description">Angezeigter Zeitbereich (Stunden 0–24).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_slot">Slot-Raster</label></th>
                    <td>
                        <input type="number" id="ad_slot"
                               name="availio_dash_options[slot_minutes]"
                               value="<?= (int) $opts['slot_minutes'] ?>"
                               min="15" max="60" step="15" style="width:5em">
                        Minuten
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_refresh">Auto-Refresh</label></th>
                    <td>
                        <input type="number" id="ad_refresh"
                               name="availio_dash_options[refresh_seconds]"
                               value="<?= (int) $opts['refresh_seconds'] ?>"
                               min="30" max="600" style="width:5em">
                        Sekunden
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_filter">Court-Filter</label></th>
                    <td>
                        <input type="text" id="ad_filter"
                               name="availio_dash_options[court_filter]"
                               value="<?= esc_attr($opts['court_filter']) ?>"
                               class="regular-text">
                        <p class="description">Komma-getrennte Court-IDs (leer = alle anzeigen).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Einstellungen speichern'); ?>
        </form>

        <hr>
        <h2>Einbindung</h2>
        <p>Shortcode auf einer beliebigen Seite oder in einem Widget einfügen:</p>
        <pre style="background:#f0f0f1;padding:0.7em 1em;display:inline-block;border-radius:4px;">[availio_dashboard]</pre>
    </div>
    <?php
}

// ── Assets registrieren ───────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', function (): void {
    wp_register_style(
        'availio-dashboard',
        AVAILIO_DASH_URL . 'assets/availio-dashboard.css',
        [],
        AVAILIO_DASH_VERSION
    );
    wp_register_script(
        'availio-dashboard',
        AVAILIO_DASH_URL . 'assets/availio-dashboard.js',
        [],
        AVAILIO_DASH_VERSION,
        true
    );
});

// ── Hilfsfunktion: Datums-Label ───────────────────────────────────────────────
function availio_dash_date_label(DateTimeImmutable $dt): string
{
    static $days   = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    static $months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun',
                      'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    return $days[(int) $dt->format('w')]
         . ' ' . $dt->format('j')
         . '. ' . $months[(int) $dt->format('n') - 1]
         . ' ' . $dt->format('Y');
}

// ── Shortcode [availio_dashboard] ─────────────────────────────────────────────
add_shortcode('availio_dashboard', function ($atts): string {
    $opts = availio_dash_get_options();

    if (empty($opts['oid']) || empty($opts['access_key'])) {
        return '<div class="availio-error">'
            . '<strong>Availio Dashboard:</strong> API-Zugangsdaten fehlen. '
            . 'Bitte unter <em>Einstellungen → Availio Dashboard</em> konfigurieren.'
            . '</div>';
    }

    wp_enqueue_style('availio-dashboard');
    wp_enqueue_script('availio-dashboard');

    $court_filter = array_values(array_filter(
        array_map('trim', explode(',', (string) $opts['court_filter']))
    ));

    $cfg = [
        'availio_base_url'   => $opts['base_url'],
        'availio_oid'        => $opts['oid'],
        'availio_access_key' => $opts['access_key'],
        'time_zone'          => $opts['time_zone'],
        'next_hours'         => 36,
        'previous_hours'     => 2,
        'day_start_hour'     => (int) $opts['day_start_hour'],
        'day_end_hour'       => (int) $opts['day_end_hour'],
        'slot_minutes'       => (int) $opts['slot_minutes'],
        'refresh_seconds'    => (int) $opts['refresh_seconds'],
        'http_timeout'       => 8,
        'court_filter'       => $court_filter,
    ];

    $api = availio_fetch($cfg);
    if (!$api['ok']) {
        return '<div class="availio-error">'
            . '<strong>Verbindungsfehler:</strong> ' . esc_html($api['error'])
            . '</div>';
    }

    $tz   = new DateTimeZone($api['timeZone']);
    $grid = availio_build_day_grid($api, $cfg, $tz);

    // Datepicker-Konfiguration
    $todayDt  = (new DateTimeImmutable('@' . $api['currentTimestamp']))->setTimezone($tz);
    $todayYmd = $todayDt->format('Y-m-d');
    $maxYmd   = $todayDt->modify('+2 days')->format('Y-m-d'); // API-Limit ~72 h

    // JS-Konfiguration übergeben (kein Token)
    wp_localize_script('availio-dashboard', 'availioDashConfig', [
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('availio_day'),
        'today'          => $todayYmd,
        'maxDate'        => $maxYmd,
        'refreshSeconds' => (int) $opts['refresh_seconds'],
    ]);

    ob_start();
    ?>
    <div class="availio-dashboard">

        <!-- Tab-Leiste mit Datepicker -->
        <div class="availio-tabs" role="tablist">

            <button class="availio-tab active"
                    role="tab" aria-selected="true"
                    data-target="availio-day">Heute</button>

            <div class="availio-date-nav" role="group" aria-label="Datum wählen">
                <button class="adn-arrow" id="availio-prev-day"
                        aria-label="Vorheriger Tag" disabled>&#9664;</button>
                <button class="availio-tab adn-label-btn"
                        role="tab" aria-selected="false"
                        data-target="availio-date"
                        id="availio-date-tab"><?= esc_html(availio_dash_date_label($todayDt)) ?></button>
                <button class="adn-arrow" id="availio-next-day"
                        aria-label="Nächster Tag">&#9654;</button>
            </div>

            <span class="availio-updated">
                Aktualisiert:
                <?= esc_html($todayDt->format('H:i')) ?> Uhr
            </span>
        </div>

        <!-- Panel: Heute (server-seitig vorgerendert) -->
        <div class="availio-panel" id="availio-day" role="tabpanel">
            <?php availio_render_day_grid($api, $grid); ?>
        </div>

        <!-- Panel: Datepicker (wird via AJAX befüllt) -->
        <div class="availio-panel availio-panel--hidden" id="availio-date" role="tabpanel">
            <div id="availio-date-content">
                <?php availio_render_day_grid($api, $grid); ?>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
});

// ── Render-Funktionen ─────────────────────────────────────────────────────────

function availio_render_day_grid(array $api, array $grid): void
{
    $courts     = $api['courts'];
    $colCount   = count($courts) + 1;
    $rowCount   = $grid['slotCount'] + 1;
    $nowIdx     = $grid['nowIdx'];
    $nowVisible = ($nowIdx >= 0 && $nowIdx < $grid['slotCount']);

    if (empty($courts)) {
        echo '<div class="availio-error">Keine Plätze verfügbar.</div>';
        return;
    }
    ?>
    <div class="availio-day-grid"
         style="--ad-cols:<?= $colCount ?>; --ad-rows:<?= $rowCount ?>;">

        <div class="ad-cell ad-head ad-time-head"
             style="grid-column:1; grid-row:1;">Zeit</div>
        <?php foreach ($courts as $i => $court): ?>
            <div class="ad-cell ad-head ad-court-head"
                 style="grid-column:<?= $i + 2 ?>; grid-row:1;">
                <?= esc_html($court['name']) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($grid['slotLabels'] as $idx => $label):
            $cls = ['ad-cell', 'ad-time'];
            if (str_ends_with($label, ':00')) $cls[] = 'ad-hour';
            if ($idx === $nowIdx)             $cls[] = 'ad-now';
        ?>
            <div class="<?= implode(' ', $cls) ?>"
                 style="grid-column:1; grid-row:<?= $idx + 2 ?>;">
                <?= esc_html($label) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($courts as $colIdx => $court):
            $col = $colIdx + 2;
            foreach ($grid['columns'][$court['id']] as $block):
                $row     = $block['startIdx'] + 2;
                $rowSpan = $block['endIdx'] - $block['startIdx'];
                $cls     = ['ad-cell', 'ad-block', 'ad-' . $block['type']];
        ?>
            <div class="<?= implode(' ', $cls) ?>"
                 style="grid-column:<?= $col ?>; grid-row:<?= $row ?> / span <?= $rowSpan ?>;">
                <?php if ($block['label']): ?>
                    <span class="ad-label"><?= esc_html($block['label']) ?></span>
                <?php endif; ?>
            </div>
        <?php
            endforeach;
        endforeach; ?>

        <?php if ($nowVisible): ?>
            <div class="ad-now-line"
                 style="grid-row:<?= $nowIdx + 2 ?>; grid-column:1 / -1;"></div>
        <?php endif; ?>

    </div>
    <?php
}
