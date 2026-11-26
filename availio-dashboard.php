<?php
/**
 * Plugin Name:       Dashboard for Availio
 * Plugin URI:        https://github.com/GianlucaD/wp_availio
 * Description:       Shows your club's court bookings from Availio (Fairgate Standard API / FSA) as an interactive dashboard with a date picker. Embed via the [availio_dashboard] shortcode.
 * Version:           1.3.0
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

define('AVAILIO_DASH_VERSION', '1.3.0');
define('AVAILIO_DASH_DIR',     plugin_dir_path(__FILE__));
define('AVAILIO_DASH_URL',     plugin_dir_url(__FILE__));

require_once AVAILIO_DASH_DIR . 'includes/availio-api.php';

// ── AJAX: day data for the date picker ────────────────────────────────────────
add_action('wp_ajax_availio_day',        'availio_dash_ajax_day');
add_action('wp_ajax_nopriv_availio_day', 'availio_dash_ajax_day');

function availio_dash_ajax_day(): void
{
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'availio_day')) {
        wp_send_json_error(__('Invalid request.', 'dashboard-for-availio'), 403);
        return;
    }

    $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_send_json_error(__('Invalid date.', 'dashboard-for-availio'));
        return;
    }

    $opts = availio_dash_get_options();
    if (empty($opts['oid']) || empty($opts['access_key'])) {
        wp_send_json_error(__('The API is not configured.', 'dashboard-for-availio'));
        return;
    }

    $tz  = new DateTimeZone($opts['time_zone']);
    $now = time();

    $reqDay = DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
    if (!$reqDay) {
        wp_send_json_error(__('Invalid date.', 'dashboard-for-availio'));
        return;
    }

    $startOfDay = $reqDay->setTime((int) $opts['day_start_hour'], 0);
    $endOfDay   = $reqDay->setTime((int) $opts['day_end_hour'], 0);

    // Hour window relative to now (API limit: 72 h)
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
        wp_send_json_error(
            sprintf(
                /* translators: %s: error message returned by the API client. */
                __('API error: %s', 'dashboard-for-availio'),
                $api['error']
            )
        );
        return;
    }

    // Set currentTimestamp to noon of the requested day so that
    // availio_build_day_grid builds the correct day.
    $api['currentTimestamp'] = $reqDay->setTime(12, 0)->getTimestamp();

    $tz   = new DateTimeZone($api['timeZone']);
    $grid = availio_build_day_grid($api, $cfg, $tz);

    ob_start();
    availio_render_day_grid($api, $grid);
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}

// ── Admin menu ────────────────────────────────────────────────────────────────
add_action('admin_menu', function (): void {
    add_options_page(
        'Dashboard for Availio',
        'Dashboard for Availio',
        'manage_options',
        'availio-dashboard',
        'availio_dash_settings_page'
    );
});

// ── Register settings ─────────────────────────────────────────────────────────
add_action('admin_init', function (): void {
    register_setting('availio_dash', 'availio_dash_options', [
        'sanitize_callback' => 'availio_dash_sanitize_options',
    ]);
});

// ── Load the color picker on the settings page only ───────────────────────────
add_action('admin_enqueue_scripts', function (string $hook): void {
    if ($hook !== 'settings_page_availio-dashboard') {
        return;
    }
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');

    $presetColors = [];
    foreach (availio_dash_color_presets() as $slug => $preset) {
        $presetColors[$slug] = $preset['colors'];
    }

    $js = 'window.availioPresets = ' . wp_json_encode($presetColors) . ';'
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

// ── Admin notice: token expiry ────────────────────────────────────────────────
// Reads the expiry date (exp) from the JWT and warns in good time.
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
        return; // No readable expiry date (e.g. a different token format).
    }

    $now         = time();
    $days        = (int) floor(($exp - $now) / DAY_IN_SECONDS);
    $settingsUrl = admin_url('options-general.php?page=availio-dashboard');

    if ($exp <= $now) {
        printf(
            '<div class="notice notice-error"><p><strong>Dashboard for Availio:</strong> %s</p></div>',
            wp_kses_post(sprintf(
                /* translators: %s: URL of the plugin settings page. */
                __('The API token has expired. Please enter a new token in the <a href="%s">settings</a>.', 'dashboard-for-availio'),
                esc_url($settingsUrl)
            ))
        );
    } elseif ($days <= 30) {
        printf(
            '<div class="notice notice-warning"><p><strong>Dashboard for Availio:</strong> %s</p></div>',
            wp_kses_post(sprintf(
                /* translators: 1: number of days, 2: expiry date, 3: URL of the plugin settings page. */
                _n(
                    'The API token expires in %1$d day (%2$s). Please renew it in good time in the <a href="%3$s">settings</a>.',
                    'The API token expires in %1$d days (%2$s). Please renew it in good time in the <a href="%3$s">settings</a>.',
                    $days,
                    'dashboard-for-availio'
                ),
                $days,
                esc_html(date_i18n(get_option('date_format'), $exp)),
                esc_url($settingsUrl)
            ))
        );
    }
});

/**
 * Reads the expiry date (exp claim) from a JWT.
 *
 * @return int|null Unix timestamp, or null when it cannot be read.
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
 * Configurable colors: key => [label, default, CSS variable(s)].
 * Used for defaults, sanitizing, the settings page and the frontend output.
 */
function availio_dash_color_fields(): array
{
    return [
        'col_page' => [
            'label'   => __('Page background', 'dashboard-for-availio'),
            'default' => '#f5f6f8',
            'vars'    => ['--ad-bg-page'],
        ],
        'col_head_bg' => [
            'label'   => __('Header – background', 'dashboard-for-availio'),
            'default' => '#2b5c8a',
            'vars'    => ['--ad-bg-head'],
        ],
        'col_head_text' => [
            'label'   => __('Header – text', 'dashboard-for-availio'),
            'default' => '#ffffff',
            'vars'    => ['--ad-color-head'],
        ],
        'col_booked_bg' => [
            'label'   => __('Booked – background', 'dashboard-for-availio'),
            'default' => '#d6e4f0',
            'vars'    => ['--ad-bg-booked'],
        ],
        'col_booked_text' => [
            'label'   => __('Booked – text', 'dashboard-for-availio'),
            'default' => '#1c3d5a',
            'vars'    => ['--ad-color-booked'],
        ],
        'col_live_bg' => [
            'label'   => __('In progress – background', 'dashboard-for-availio'),
            'default' => '#2b7bd6',
            'vars'    => ['--ad-bg-live'],
        ],
        'col_live_text' => [
            'label'   => __('In progress – text', 'dashboard-for-availio'),
            'default' => '#ffffff',
            'vars'    => ['--ad-color-live'],
        ],
        'col_accent' => [
            'label'   => __('Accent / current-time line', 'dashboard-for-availio'),
            'default' => '#c0392b',
            'vars'    => ['--ad-color-now', '--ad-line-now'],
        ],
    ];
}

/**
 * Ready-made color presets: slug => [label, colors per color field].
 * The slug is the stable identifier used in the markup; only the label is translated.
 */
function availio_dash_color_presets(): array
{
    return [
        'blue' => [
            'label'  => __('Blue', 'dashboard-for-availio'),
            'colors' => [
                'col_page' => '#f5f6f8', 'col_head_bg' => '#2b5c8a', 'col_head_text' => '#ffffff',
                'col_booked_bg' => '#d6e4f0', 'col_booked_text' => '#1c3d5a',
                'col_live_bg' => '#2b7bd6', 'col_live_text' => '#ffffff', 'col_accent' => '#c0392b',
            ],
        ],
        'green' => [
            'label'  => __('Green', 'dashboard-for-availio'),
            'colors' => [
                'col_page' => '#f4f7f2', 'col_head_bg' => '#2f7d3f', 'col_head_text' => '#ffffff',
                'col_booked_bg' => '#d8eadb', 'col_booked_text' => '#1f3d2a',
                'col_live_bg' => '#3a9d4e', 'col_live_text' => '#ffffff', 'col_accent' => '#b4441f',
            ],
        ],
        'brick' => [
            'label'  => __('Brick', 'dashboard-for-availio'),
            'colors' => [
                'col_page' => '#faf5f1', 'col_head_bg' => '#b5531f', 'col_head_text' => '#ffffff',
                'col_booked_bg' => '#f3e0d3', 'col_booked_text' => '#6b3315',
                'col_live_bg' => '#e07a34', 'col_live_text' => '#ffffff', 'col_accent' => '#1f6f6f',
            ],
        ],
        'gray' => [
            'label'  => __('Gray', 'dashboard-for-availio'),
            'colors' => [
                'col_page' => '#f5f5f5', 'col_head_bg' => '#444444', 'col_head_text' => '#ffffff',
                'col_booked_bg' => '#e4e4e4', 'col_booked_text' => '#333333',
                'col_live_bg' => '#666666', 'col_live_text' => '#ffffff', 'col_accent' => '#c0392b',
            ],
        ],
        'dark' => [
            'label'  => __('Dark', 'dashboard-for-availio'),
            'colors' => [
                'col_page' => '#1e1e1e', 'col_head_bg' => '#111111', 'col_head_text' => '#ffffff',
                'col_booked_bg' => '#2a2f3a', 'col_booked_text' => '#cfd8e3',
                'col_live_bg' => '#3b82f6', 'col_live_text' => '#ffffff', 'col_accent' => '#f59e0b',
            ],
        ],
    ];
}

/**
 * Turns the stored color options into a CSS rule that overrides the
 * CSS variables on .availio-dashboard.
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

    // Optional: override the credentials with constants from wp-config.php.
    // Anyone who does not want secrets in the database defines e.g.:
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

/** Checks whether a field is set via a wp-config.php constant (then shown read-only). */
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

    // Colors: accept valid hex values, otherwise fall back to the default.
    foreach (availio_dash_color_fields() as $key => $def) {
        $val = sanitize_hex_color($input[$key] ?? '');
        $out[$key] = $val ?: $def['default'];
    }

    return $out;
}

// ── Settings page ─────────────────────────────────────────────────────────────
function availio_dash_settings_page(): void
{
    $opts = availio_dash_get_options();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Dashboard for Availio – Settings', 'dashboard-for-availio'); ?></h1>
        <p><?php echo esc_html__('Configure the connection to the Availio (Fairgate FSA) API and the display options here.', 'dashboard-for-availio'); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields('availio_dash'); ?>

            <h2><?php echo esc_html__('API access', 'dashboard-for-availio'); ?></h2>
            <?php
            $oidConst  = availio_dash_const_active('oid');
            $keyConst  = availio_dash_const_active('access_key');
            $baseConst = availio_dash_const_active('base_url');
            if ($oidConst || $keyConst || $baseConst): ?>
                <p class="description" style="max-width:40em;">
                    <?php
                    echo wp_kses_post(
                        __('One or more values are set via constants in <code>wp-config.php</code> and cannot be changed here (recommended if you do not want to keep secrets in the database).', 'dashboard-for-availio')
                    );
                    ?>
                </p>
            <?php endif; ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ad_oid"><?php echo esc_html__('Organisation ID (OID)', 'dashboard-for-availio'); ?></label></th>
                    <td>
                        <input type="text" id="ad_oid"
                               name="availio_dash_options[oid]"
                               value="<?php echo esc_attr($opts['oid']); ?>"
                               class="regular-text" autocomplete="off"
                               <?php echo $oidConst ? 'readonly' : ''; ?>>
                        <p class="description">
                            <?php echo esc_html__('The OID of your club as it appears in the Availio URL.', 'dashboard-for-availio'); ?>
                            <?php if ($oidConst): ?>
                                <strong><?php
                                printf(
                                    /* translators: %s: name of the PHP constant, wrapped in a code element. */
                                    esc_html__('Set via %s in wp-config.php.', 'dashboard-for-availio'),
                                    '<code>AVAILIO_OID</code>'
                                );
                                ?></strong>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_key"><?php echo esc_html__('API token', 'dashboard-for-availio'); ?></label></th>
                    <td>
                        <input type="password" id="ad_key"
                               name="availio_dash_options[access_key]"
                               value="<?php echo $keyConst ? '' : esc_attr($opts['access_key']); ?>"
                               class="regular-text" autocomplete="off"
                               <?php if ($keyConst): ?>
                                   readonly placeholder="<?php echo esc_attr__('(set via wp-config.php)', 'dashboard-for-availio'); ?>"
                               <?php endif; ?>>
                        <p class="description">
                            <?php
                            echo wp_kses_post(
                                __('API token from your Availio settings. <strong>Important:</strong> in Availio, under &#8220;Authentication methods&#8221;, choose the option <em>&#8220;Specific short API token&#8221;</em> (long-lived, no expiry). A JWT expires automatically, which makes the dashboard fail regularly. The specific API token is set up by Fairgate support on request.', 'dashboard-for-availio')
                            );
                            ?>
                            <?php if ($keyConst): ?>
                                <strong><?php
                                printf(
                                    /* translators: %s: name of the PHP constant, wrapped in a code element. */
                                    esc_html__('Set via %s in wp-config.php.', 'dashboard-for-availio'),
                                    '<code>AVAILIO_ACCESS_KEY</code>'
                                );
                                ?></strong>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_base"><?php echo esc_html__('API base URL', 'dashboard-for-availio'); ?></label></th>
                    <td>
                        <input type="url" id="ad_base"
                               name="availio_dash_options[base_url]"
                               value="<?php echo esc_attr($opts['base_url']); ?>"
                               class="regular-text"
                               <?php echo $baseConst ? 'readonly' : ''; ?>>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: the default base URL, wrapped in a code element. */
                                esc_html__('Default: %s', 'dashboard-for-availio'),
                                '<code>https://fsa.fairgate.ch</code>'
                            );
                            ?>
                            <?php if ($baseConst): ?>
                                <strong><?php
                                printf(
                                    /* translators: %s: name of the PHP constant, wrapped in a code element. */
                                    esc_html__('Set via %s in wp-config.php.', 'dashboard-for-availio'),
                                    '<code>AVAILIO_BASE_URL</code>'
                                );
                                ?></strong>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php echo esc_html__('Display options', 'dashboard-for-availio'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ad_club"><?php echo esc_html__('Club name', 'dashboard-for-availio'); ?></label></th>
                    <td>
                        <input type="text" id="ad_club"
                               name="availio_dash_options[club_name]"
                               value="<?php echo esc_attr($opts['club_name']); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_tz"><?php echo esc_html__('Time zone', 'dashboard-for-availio'); ?></label></th>
                    <td>
                        <input type="text" id="ad_tz"
                               name="availio_dash_options[time_zone]"
                               value="<?php echo esc_attr($opts['time_zone']); ?>"
                               class="regular-text">
                        <p class="description"><?php
                        printf(
                            /* translators: %s: example time zone identifier, wrapped in a code element. */
                            esc_html__('For example %s', 'dashboard-for-availio'),
                            '<code>Europe/Zurich</code>'
                        );
                        ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Display period', 'dashboard-for-availio'); ?></th>
                    <td>
                        <label>
                            <?php echo esc_html__('From hour:', 'dashboard-for-availio'); ?>
                            <input type="number"
                                   name="availio_dash_options[day_start_hour]"
                                   value="<?php echo (int) $opts['day_start_hour']; ?>"
                                   min="0" max="23" style="width:4.5em">
                        </label>
                        &ensp;
                        <label>
                            <?php echo esc_html__('To hour:', 'dashboard-for-availio'); ?>
                            <input type="number"
                                   name="availio_dash_options[day_end_hour]"
                                   value="<?php echo (int) $opts['day_end_hour']; ?>"
                                   min="1" max="24" style="width:4.5em">
                        </label>
                        <p class="description"><?php echo esc_html__('Displayed time range (hours 0–24).', 'dashboard-for-availio'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_slot"><?php echo esc_html__('Slot size', 'dashboard-for-availio'); ?></label></th>
                    <td>
                        <input type="number" id="ad_slot"
                               name="availio_dash_options[slot_minutes]"
                               value="<?php echo (int) $opts['slot_minutes']; ?>"
                               min="15" max="60" step="15" style="width:5em">
                        <?php echo esc_html__('minutes', 'dashboard-for-availio'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_refresh"><?php echo esc_html__('Auto-refresh', 'dashboard-for-availio'); ?></label></th>
                    <td>
                        <input type="number" id="ad_refresh"
                               name="availio_dash_options[refresh_seconds]"
                               value="<?php echo (int) $opts['refresh_seconds']; ?>"
                               min="30" max="600" style="width:5em">
                        <?php echo esc_html__('seconds', 'dashboard-for-availio'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ad_filter"><?php echo esc_html__('Court filter', 'dashboard-for-availio'); ?></label></th>
                    <td>
                        <input type="text" id="ad_filter"
                               name="availio_dash_options[court_filter]"
                               value="<?php echo esc_attr($opts['court_filter']); ?>"
                               class="regular-text">
                        <p class="description"><?php echo esc_html__('Comma-separated court IDs (empty = show all).', 'dashboard-for-availio'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php echo esc_html__('Color scheme', 'dashboard-for-availio'); ?></h2>
            <p class="description" style="max-width:40em;">
                <?php echo esc_html__('Choose a preset or adjust the colors individually to match your club design. An empty or invalid field falls back to the default.', 'dashboard-for-availio'); ?>
            </p>
            <p>
                <strong><?php echo esc_html__('Presets:', 'dashboard-for-availio'); ?></strong>&ensp;
                <?php foreach (availio_dash_color_presets() as $slug => $preset): ?>
                    <button type="button" class="button availio-preset"
                            data-preset="<?php echo esc_attr($slug); ?>"><?php echo esc_html($preset['label']); ?></button>
                <?php endforeach; ?>
                <span class="description" style="margin-left:.5em;"><?php echo esc_html__('(remember to save afterwards)', 'dashboard-for-availio'); ?></span>
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

            <?php submit_button(__('Save settings', 'dashboard-for-availio')); ?>
        </form>

        <hr>
        <h2><?php echo esc_html__('Embedding', 'dashboard-for-availio'); ?></h2>
        <p><?php echo esc_html__('Insert the shortcode on any page or in a widget:', 'dashboard-for-availio'); ?></p>
        <pre style="background:#f0f0f1;padding:0.7em 1em;display:inline-block;border-radius:4px;">[availio_dashboard]</pre>
    </div>
    <?php
}

// ── Register assets ───────────────────────────────────────────────────────────
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

// ── Helper: date label ────────────────────────────────────────────────────────
function availio_dash_date_label(DateTimeImmutable $dt): string
{
    return (string) wp_date(
        /* translators: PHP date format for the day tab. See https://www.php.net/manual/datetime.format.php */
        _x('D j. M Y', 'date format for the day tab', 'dashboard-for-availio'),
        $dt->getTimestamp(),
        $dt->getTimezone()
    );
}

/**
 * Abbreviated weekday and month names of the current locale, for the JavaScript side.
 *
 * @return array{days: list<string>, months: list<string>}
 */
function availio_dash_locale_names(): array
{
    global $wp_locale;

    $days   = [];
    $months = [];

    if ($wp_locale instanceof WP_Locale) {
        for ($i = 0; $i < 7; $i++) {
            $days[] = $wp_locale->get_weekday_abbrev($wp_locale->get_weekday($i));
        }
        for ($m = 1; $m <= 12; $m++) {
            $months[] = $wp_locale->get_month_abbrev($wp_locale->get_month($m));
        }
    }

    return ['days' => $days, 'months' => $months];
}

// ── Shortcode [availio_dashboard] ─────────────────────────────────────────────
add_shortcode('availio_dashboard', function ($atts): string {
    $opts = availio_dash_get_options();

    if (empty($opts['oid']) || empty($opts['access_key'])) {
        return '<div class="availio-error"><strong>Dashboard for Availio:</strong> '
            . wp_kses_post(__('The API credentials are missing. Please configure them under <em>Settings → Dashboard for Availio</em>.', 'dashboard-for-availio'))
            . '</div>';
    }

    wp_enqueue_style('availio-dashboard');
    wp_enqueue_script('availio-dashboard');

    // Inject the individual color scheme as CSS variables.
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
        return '<div class="availio-error"><strong>'
            . esc_html__('Connection error:', 'dashboard-for-availio') . '</strong> '
            . esc_html($api['error'])
            . '</div>';
    }

    $tz   = new DateTimeZone($api['timeZone']);
    $grid = availio_build_day_grid($api, $cfg, $tz);

    // Date picker configuration
    $todayDt  = (new DateTimeImmutable('@' . $api['currentTimestamp']))->setTimezone($tz);
    $todayYmd = $todayDt->format('Y-m-d');
    $maxYmd   = $todayDt->modify('+2 days')->format('Y-m-d'); // API limit ~72 h

    // Pass the JS configuration (no token)
    $localeNames = availio_dash_locale_names();
    wp_localize_script('availio-dashboard', 'availioDashConfig', [
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('availio_day'),
        'today'          => $todayYmd,
        'maxDate'        => $maxYmd,
        'refreshSeconds' => (int) $opts['refresh_seconds'],
        'dayNames'       => $localeNames['days'],
        'monthNames'     => $localeNames['months'],
        'i18n'           => [
            'loading'         => __('Loading data…', 'dashboard-for-availio'),
            'errorPrefix'     => __('Error:', 'dashboard-for-availio'),
            'unknownError'    => __('Unknown error', 'dashboard-for-availio'),
            'connectionError' => __('Connection error.', 'dashboard-for-availio'),
        ],
    ]);

    ob_start();
    ?>
    <div class="availio-dashboard">

        <?php if (!empty($opts['club_name'])): ?>
            <div class="availio-title"><?php echo esc_html($opts['club_name']); ?></div>
        <?php endif; ?>

        <!-- Tab bar with date picker -->
        <div class="availio-tabs" role="tablist">

            <button class="availio-tab active"
                    role="tab" aria-selected="true"
                    data-target="availio-day"><?php echo esc_html__('Today', 'dashboard-for-availio'); ?></button>

            <div class="availio-date-nav" role="group"
                 aria-label="<?php echo esc_attr__('Choose date', 'dashboard-for-availio'); ?>">
                <button class="adn-arrow" id="availio-prev-day"
                        aria-label="<?php echo esc_attr__('Previous day', 'dashboard-for-availio'); ?>" disabled>&#9664;</button>
                <button class="availio-tab adn-label-btn"
                        role="tab" aria-selected="false"
                        data-target="availio-date"
                        id="availio-date-tab"><?php echo esc_html(availio_dash_date_label($todayDt)); ?></button>
                <button class="adn-arrow" id="availio-next-day"
                        aria-label="<?php echo esc_attr__('Next day', 'dashboard-for-availio'); ?>">&#9654;</button>
            </div>

            <span class="availio-updated">
                <?php
                printf(
                    /* translators: %s: time of the last update, e.g. 14:05 */
                    esc_html__('Updated: %s', 'dashboard-for-availio'),
                    esc_html(wp_date((string) get_option('time_format'), $todayDt->getTimestamp(), $tz))
                );
                ?>
            </span>
        </div>

        <!-- Panel: today (pre-rendered server-side) -->
        <div class="availio-panel" id="availio-day" role="tabpanel">
            <?php availio_render_day_grid($api, $grid); ?>
        </div>

        <!-- Panel: date picker (filled via AJAX) -->
        <div class="availio-panel availio-panel--hidden" id="availio-date" role="tabpanel">
            <div id="availio-date-content">
                <?php availio_render_day_grid($api, $grid); ?>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
});

// ── Render functions ──────────────────────────────────────────────────────────

function availio_render_day_grid(array $api, array $grid): void
{
    $courts     = $api['courts'];
    $colCount   = count($courts) + 1;
    $rowCount   = $grid['slotCount'] + 1;
    $nowIdx     = $grid['nowIdx'];
    $nowVisible = ($nowIdx >= 0 && $nowIdx < $grid['slotCount']);

    if (empty($courts)) {
        echo '<div class="availio-error">'
            . esc_html__('No courts available.', 'dashboard-for-availio')
            . '</div>';
        return;
    }
    ?>
    <div class="availio-day-grid"
         style="--ad-cols:<?php echo (int) $colCount; ?>; --ad-rows:<?php echo (int) $rowCount; ?>;">

        <div class="ad-cell ad-head ad-time-head"
             style="grid-column:1; grid-row:1;"><?php echo esc_html__('Time', 'dashboard-for-availio'); ?></div>
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
