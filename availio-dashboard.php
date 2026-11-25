<?php
/**
 * Plugin Name:       Dashboard for Availio
 * Plugin URI:        https://github.com/GianlucaD/wp_availio
 * Description:       Zeigt Platz-Buchungen aus Availio (Fairgate Standard-API / FSA) als interaktives Dashboard mit Datepicker. Einbindung via Shortcode [availio_dashboard].
 * Version:           1.2.2
 * Author:            GianlucaD
 * Author URI:        https://github.com/GianlucaD
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dashboard-for-availio
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AVAILIO_DASH_VERSION', '1.2.2');
define('AVAILIO_DASH_DIR',     plugin_dir_path(__FILE__));
define('AVAILIO_DASH_URL',     plugin_dir_url(__FILE__));

require_once AVAILIO_DASH_DIR . 'includes/availio-api.php';

// ── AJAX: Tages-Daten für den Datepicker ──────────────────────────────────────
add_action('wp_ajax_availio_day',        'availio_dash_ajax_day');
add_action('wp_ajax_nopriv_availio_day', 'availio_dash_ajax_day');

function availio_dash_ajax_day(): void
{
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'availio_day')) {
        wp_send_json_error('Ungültige Anfrage.', 403);
        return;
    }

    $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
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
        'Dashboard for Availio',
        'Dashboard for Availio',
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

// ── Color-Picker nur auf der Einstellungsseite laden ──────────────────────────
add_action('admin_enqueue_scripts', function (string $hook): void {
    if ($hook !== 'settings_page_availio-dashboard') {
        return;
    }
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');

    $presets = wp_json_encode(availio_dash_color_presets());
    $js = 'window.availioPresets = ' . $presets . ';'
        . 'jQuery(function($){'
        . '  $(".availio-color-field").wpColorPicker();'
        . '  $(".availio-preset").on("click", function(e){'
        . '    e.preventDefault();'
        . '    var p = window.availioPresets[$(this).data("preset")];'
        . '    if(!p){return;}'
        . '    $.each(p, function(key, hex){ $("#ad_"+key).wpColorPicker("color", hex); });'
        . '  });'
        . '});';
    wp_add_inline_script('wp-color-picker', $js);
});

// ── Admin-Hinweis: Token-Ablauf ───────────────────────────────────────────────
// Liest das Ablaufdatum (exp) aus dem JWT und warnt rechtzeitig im Admin.
add_action('admin_notices', function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $opts = availio_dash_get_options();
    $key  = (string) ($opts['access_key'] ?? '');
    if ($key === '') {
        return;
    }
    $exp = availio_dash_token_exp($key);
    if ($exp === null) {
        return; // kein lesbares Ablaufdatum (z.B. anderes Token-Format)
    }

    $now         = time();
    $days        = (int) floor(($exp - $now) / DAY_IN_SECONDS);
    $settingsUrl = admin_url('options-general.php?page=availio-dashboard');

    if ($exp <= $now) {
        printf(
            '<div class="notice notice-error"><p><strong>Dashboard for Availio:</strong> Der API-Token ist abgelaufen. Bitte einen neuen Token in den <a href="%s">Einstellungen</a> eintragen.</p></div>',
            esc_url($settingsUrl)
        );
    } elseif ($days <= 30) {
        printf(
            '<div class="notice notice-warning"><p><strong>Dashboard for Availio:</strong> Der API-Token läuft in %d Tagen ab (%s). Bitte rechtzeitig in den <a href="%s">Einstellungen</a> erneuern.</p></div>',
            (int) $days,
            esc_html(date_i18n(get_option('date_format'), $exp)),
            esc_url($settingsUrl)
        );
    }
});

/**
 * Liest das Ablaufdatum (exp-Claim) aus einem JWT.
 *
 * @return int|null Unix-Timestamp oder null, wenn nicht lesbar.
 */
function availio_dash_token_exp(string $jwt): ?int
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return null;
    }
    $payload = strtr($parts[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $json = base64_decode($payload, true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['exp'])) {
        return null;
    }
    return (int) $data['exp'];
}

/**
 * Konfigurierbare Farben: Schlüssel => [Label, Standardwert, CSS-Variable(n)].
 * Wird für Defaults, Sanitizing, Einstellungsseite und Frontend-Ausgabe genutzt.
 */
function availio_dash_color_fields(): array
{
    return [
        'col_page'        => ['label' => 'Seiten-Hintergrund',      'default' => '#f5f6f8', 'vars' => ['--ad-bg-page']],
        'col_head_bg'     => ['label' => 'Kopfzeile – Hintergrund', 'default' => '#2b5c8a', 'vars' => ['--ad-bg-head']],
        'col_head_text'   => ['label' => 'Kopfzeile – Text',        'default' => '#ffffff', 'vars' => ['--ad-color-head']],
        'col_booked_bg'   => ['label' => 'Belegt – Hintergrund',    'default' => '#d6e4f0', 'vars' => ['--ad-bg-booked']],
        'col_booked_text' => ['label' => 'Belegt – Text',           'default' => '#1c3d5a', 'vars' => ['--ad-color-booked']],
        'col_live_bg'     => ['label' => 'Laufend – Hintergrund',   'default' => '#2b7bd6', 'vars' => ['--ad-bg-live']],
        'col_live_text'   => ['label' => 'Laufend – Text',          'default' => '#ffffff', 'vars' => ['--ad-color-live']],
        'col_accent'      => ['label' => 'Akzent / Jetzt-Linie',    'default' => '#c0392b', 'vars' => ['--ad-color-now', '--ad-line-now']],
    ];
}

/**
 * Fertige Farb-Voreinstellungen: Name => Farbwerte je Farbfeld.
 */
function availio_dash_color_presets(): array
{
    return [
        'Blau' => [
            'col_page' => '#f5f6f8', 'col_head_bg' => '#2b5c8a', 'col_head_text' => '#ffffff',
            'col_booked_bg' => '#d6e4f0', 'col_booked_text' => '#1c3d5a',
            'col_live_bg' => '#2b7bd6', 'col_live_text' => '#ffffff', 'col_accent' => '#c0392b',
        ],
        'Grün' => [
            'col_page' => '#f4f7f2', 'col_head_bg' => '#2f7d3f', 'col_head_text' => '#ffffff',
            'col_booked_bg' => '#d8eadb', 'col_booked_text' => '#1f3d2a',
            'col_live_bg' => '#3a9d4e', 'col_live_text' => '#ffffff', 'col_accent' => '#b4441f',
        ],
        'Ziegel' => [
            'col_page' => '#faf5f1', 'col_head_bg' => '#b5531f', 'col_head_text' => '#ffffff',
            'col_booked_bg' => '#f3e0d3', 'col_booked_text' => '#6b3315',
            'col_live_bg' => '#e07a34', 'col_live_text' => '#ffffff', 'col_accent' => '#1f6f6f',
        ],
        'Grau' => [
            'col_page' => '#f5f5f5', 'col_head_bg' => '#444444', 'col_head_text' => '#ffffff',
            'col_booked_bg' => '#e4e4e4', 'col_booked_text' => '#333333',
            'col_live_bg' => '#666666', 'col_live_text' => '#ffffff', 'col_accent' => '#c0392b',
        ],
        'Dunkel' => [
            'col_page' => '#1e1e1e', 'col_head_bg' => '#111111', 'col_head_text' => '#ffffff',
            'col_booked_bg' => '#2a2f3a', 'col_booked_text' => '#cfd8e3',
            'col_live_bg' => '#3b82f6', 'col_live_text' => '#ffffff', 'col_accent' => '#f59e0b',
        ],
    ];
}

/**
 * Baut aus den gespeicherten Farboptionen eine CSS-Regel, die die
 * CSS-Variablen auf .availio-dashboard übersteuert.
 */
function availio_dash_color_css(array $opts): string
{
    $decls = [];
    foreach (availio_dash_color_fields() as $key => $def) {
        $hex = sanitize_hex_color((string) ($opts[$key] ?? '')) ?: $def['default'];
        foreach ($def['vars'] as $var) {
            $decls[] = $var . ':' . $hex;
        }
    }
    if (!$decls) {
        return '';
    }
    return '.availio-dashboard{' . implode(';', $decls) . '}';
}

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
    foreach (availio_dash_color_fields() as $key => $def) {
        $defaults[$key] = $def['default'];
    }
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
    $out = [
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

    // Farben: gültige Hex-Werte übernehmen, sonst auf Standard zurückfallen.
    foreach (availio_dash_color_fields() as $key => $def) {
        $val = sanitize_hex_color($input[$key] ?? '');
        $out[$key] = $val ?: $def['default'];
    }

    return $out;
}

// ── Einstellungs-Seite ────────────────────────────────────────────────────────
function availio_dash_settings_page(): void
{
    $opts = availio_dash_get_options();
    ?>
    <div class="wrap">
        <h1>Dashboard for Availio – Einstellungen</h1>
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
                               value="<?php echo esc_attr($opts['oid']); ?>"
                               class="regular-text" autocomplete="off"
                               <?php echo $oidConst ? 'readonly' : ''; ?>>
                        <p class="description">
                            Die OID deines Clubs in der Availio-URL.
                            <?php echo $oidConst ? '<strong>Per <code>AVAILIO_OID</code> in wp-config.php gesetzt.</strong>' : ''; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_key">API-Token</label></th>
                    <td>
                        <input type="password" id="ad_key"
                               name="availio_dash_options[access_key]"
                               value="<?php echo $keyConst ? '' : esc_attr($opts['access_key']); ?>"
                               class="regular-text" autocomplete="off"
                               <?php echo $keyConst ? 'readonly placeholder="(über wp-config.php gesetzt)"' : ''; ?>>
                        <p class="description">
                            API-Token aus den Availio-Einstellungen.
                            <strong>Wichtig:</strong> In Availio unter „Authentifizierungsmethoden“ die Option
                            <em>„Spezifischer kurzer API-Token“</em> wählen (langlebig, ohne Ablaufzeit).
                            Ein JWT läuft automatisch ab, wodurch das Dashboard regelmässig ausfällt.
                            Der spezifische API-Token wird auf Anfrage vom Fairgate-Support eingerichtet.
                            <?php echo $keyConst ? '<strong>Per <code>AVAILIO_ACCESS_KEY</code> in wp-config.php gesetzt.</strong>' : ''; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_base">API-Basis-URL</label></th>
                    <td>
                        <input type="url" id="ad_base"
                               name="availio_dash_options[base_url]"
                               value="<?php echo esc_attr($opts['base_url']); ?>"
                               class="regular-text"
                               <?php echo $baseConst ? 'readonly' : ''; ?>>
                        <p class="description">
                            Standard: <code>https://fsa.fairgate.ch</code>
                            <?php echo $baseConst ? '<strong>Per <code>AVAILIO_BASE_URL</code> in wp-config.php gesetzt.</strong>' : ''; ?>
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
                               value="<?php echo esc_attr($opts['club_name']); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_tz">Zeitzone</label></th>
                    <td>
                        <input type="text" id="ad_tz"
                               name="availio_dash_options[time_zone]"
                               value="<?php echo esc_attr($opts['time_zone']); ?>"
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
                                   value="<?php echo (int) $opts['day_start_hour']; ?>"
                                   min="0" max="23" style="width:4.5em">
                        </label>
                        &ensp;
                        <label>
                            Bis Stunde:
                            <input type="number"
                                   name="availio_dash_options[day_end_hour]"
                                   value="<?php echo (int) $opts['day_end_hour']; ?>"
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
                               value="<?php echo (int) $opts['slot_minutes']; ?>"
                               min="15" max="60" step="15" style="width:5em">
                        Minuten
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_refresh">Auto-Refresh</label></th>
                    <td>
                        <input type="number" id="ad_refresh"
                               name="availio_dash_options[refresh_seconds]"
                               value="<?php echo (int) $opts['refresh_seconds']; ?>"
                               min="30" max="600" style="width:5em">
                        Sekunden
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_filter">Court-Filter</label></th>
                    <td>
                        <input type="text" id="ad_filter"
                               name="availio_dash_options[court_filter]"
                               value="<?php echo esc_attr($opts['court_filter']); ?>"
                               class="regular-text">
                        <p class="description">Komma-getrennte Court-IDs (leer = alle anzeigen).</p>
                    </td>
                </tr>
            </table>

            <h2>Farbschema</h2>
            <p class="description" style="max-width:40em;">
                Wähle eine Voreinstellung oder passe die Farben einzeln an dein
                Vereins-Design an. Leeres oder ungültiges Feld setzt auf den Standard zurück.
            </p>
            <p>
                <strong>Voreinstellungen:</strong>&ensp;
                <?php foreach (array_keys(availio_dash_color_presets()) as $preset): ?>
                    <button type="button" class="button availio-preset"
                            data-preset="<?php echo esc_attr($preset); ?>"><?php echo esc_html($preset); ?></button>
                <?php endforeach; ?>
                <span class="description" style="margin-left:.5em;">(danach speichern nicht vergessen)</span>
            </p>
            <table class="form-table" role="presentation">
                <?php foreach (availio_dash_color_fields() as $key => $def): ?>
                    <tr>
                        <th scope="row">
                            <label for="ad_<?php echo esc_attr($key); ?>"><?php echo esc_html($def['label']); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ad_<?php echo esc_attr($key); ?>"
                                   class="availio-color-field"
                                   name="availio_dash_options[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr($opts[$key]); ?>"
                                   data-default-color="<?php echo esc_attr($def['default']); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
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
            . '<strong>Dashboard for Availio:</strong> API-Zugangsdaten fehlen. '
            . 'Bitte unter <em>Einstellungen → Dashboard for Availio</em> konfigurieren.'
            . '</div>';
    }

    wp_enqueue_style('availio-dashboard');
    wp_enqueue_script('availio-dashboard');

    // Individuelles Farbschema als CSS-Variablen einspeisen.
    $colorCss = availio_dash_color_css($opts);
    if ($colorCss !== '') {
        wp_add_inline_style('availio-dashboard', $colorCss);
    }

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

        <?php if (!empty($opts['club_name'])): ?>
            <div class="availio-title"><?php echo esc_html($opts['club_name']); ?></div>
        <?php endif; ?>

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
                        id="availio-date-tab"><?php echo esc_html(availio_dash_date_label($todayDt)); ?></button>
                <button class="adn-arrow" id="availio-next-day"
                        aria-label="Nächster Tag">&#9654;</button>
            </div>

            <span class="availio-updated">
                Aktualisiert:
                <?php echo esc_html($todayDt->format('H:i')); ?> Uhr
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
         style="--ad-cols:<?php echo (int) $colCount; ?>; --ad-rows:<?php echo (int) $rowCount; ?>;">

        <div class="ad-cell ad-head ad-time-head"
             style="grid-column:1; grid-row:1;">Zeit</div>
        <?php foreach ($courts as $i => $court): ?>
            <div class="ad-cell ad-head ad-court-head"
                 style="grid-column:<?php echo (int) ($i + 2); ?>; grid-row:1;">
                <?php echo esc_html($court['name']); ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($grid['slotLabels'] as $idx => $label):
            $cls = ['ad-cell', 'ad-time'];
            if (str_ends_with($label, ':00')) $cls[] = 'ad-hour';
            if ($idx === $nowIdx)             $cls[] = 'ad-now';
        ?>
            <div class="<?php echo esc_attr(implode(' ', $cls)); ?>"
                 style="grid-column:1; grid-row:<?php echo (int) ($idx + 2); ?>;">
                <?php echo esc_html($label); ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($courts as $colIdx => $court):
            $col = $colIdx + 2;
            foreach ($grid['columns'][$court['id']] as $block):
                $row     = $block['startIdx'] + 2;
                $rowSpan = $block['endIdx'] - $block['startIdx'];
                $cls     = ['ad-cell', 'ad-block', 'ad-' . $block['type']];
        ?>
            <div class="<?php echo esc_attr(implode(' ', $cls)); ?>"
                 style="grid-column:<?php echo (int) $col; ?>; grid-row:<?php echo (int) $row; ?> / span <?php echo (int) $rowSpan; ?>;">
                <?php if ($block['label']): ?>
                    <span class="ad-label"><?php echo esc_html($block['label']); ?></span>
                <?php endif; ?>
            </div>
        <?php
            endforeach;
        endforeach; ?>

        <?php if ($nowVisible): ?>
            <div class="ad-now-line"
                 style="grid-row:<?php echo (int) ($nowIdx + 2); ?>; grid-column:1 / -1;"></div>
        <?php endif; ?>

    </div>
    <?php
}
