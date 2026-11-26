<?php
// includes/availio-api.php
// -----------------------------------------------------------------------------
// Availio (Fairgate FSA) API client – WordPress plugin version.
// Keeps the bearer token server-side; it is never passed to the browser.
// -----------------------------------------------------------------------------

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches reservations and the court list from Availio.
 *
 * IMPORTANT: the Fairgate FSA returns HTTP 500 when no User-Agent header is set,
 *            so the user agent must always be passed explicitly.
 *
 * @return array{
 *     ok: bool,
 *     error?: string,
 *     currentTimestamp?: int,
 *     timeZone?: string,
 *     courts?: list<array>,
 *     reservations?: list<array>,
 * }
 */
function availio_fetch(array $cfg): array
{
    $url = sprintf(
        '%s/fsa/v1.0/booking/%s/reservations?nextHours=%d&previousHours=%d',
        rtrim($cfg['availio_base_url'], '/'),
        rawurlencode($cfg['availio_oid']),
        (int) $cfg['next_hours'],
        (int) $cfg['previous_hours']
    );

    $response = wp_remote_get($url, [
        'timeout'    => (int) $cfg['http_timeout'],
        'user-agent' => 'availio-wp-dashboard/1.0',
        'headers'    => [
            'Authorization' => 'Bearer ' . $cfg['availio_access_key'],
            'Accept'        => 'application/json',
        ],
        'sslverify'  => true,
    ]);

    if (is_wp_error($response)) {
        return [
            'ok'    => false,
            'error' => sprintf(
                /* translators: %s: error message from the HTTP request. */
                __('Connection error: %s', 'dashboard-for-availio'),
                $response->get_error_message()
            ),
        ];
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body   = (string) wp_remote_retrieve_body($response);

    return availio_parse_response($status, $body, $cfg);
}

/** Shared response handling */
function availio_parse_response(int $status, string $body, array $cfg): array
{
    if ($status === 401) {
        return [
            'ok'    => false,
            'error' => __('Token invalid or expired (HTTP 401).', 'dashboard-for-availio'),
        ];
    }
    if ($status !== 200) {
        return [
            'ok'    => false,
            'error' => sprintf(
                /* translators: %d: HTTP status code. */
                __('API error: HTTP %d.', 'dashboard-for-availio'),
                $status
            ),
        ];
    }

    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['data'])) {
        return [
            'ok'    => false,
            'error' => __('The API response could not be parsed.', 'dashboard-for-availio'),
        ];
    }

    $data   = $json['data'];
    $courts = $data['club']['courts'] ?? [];

    // Optional: apply the court filter
    if (!empty($cfg['court_filter'])) {
        $allow  = array_flip($cfg['court_filter']);
        $courts = array_values(array_filter(
            $courts,
            static fn($c) => isset($allow[$c['id']])
        ));
    }

    // Filter out cancelled reservations
    $reservations = array_values(array_filter(
        $data['reservations'] ?? [],
        static fn($r) => empty($r['deleteTimestamp'])
    ));

    return [
        'ok'               => true,
        'currentTimestamp' => (int) ($data['currentTimestamp'] ?? time()),
        'timeZone'         => $data['club']['timeZone'] ?? $cfg['time_zone'],
        'courts'           => $courts,
        'reservations'     => $reservations,
    ];
}

// ── Day-view grid ─────────────────────────────────────────────────────────

/**
 * Builds the grid for the day view.
 *
 * Every reservation becomes a block; free ranges become blocks as well,
 * so that row-span rendering has no gaps.
 *
 * @return array{
 *     slotLabels: list<string>,
 *     slotCount: int,
 *     dayStart: int,
 *     dayEnd: int,
 *     nowIdx: int,
 *     columns: array<string, list<array>>,
 * }
 */
function availio_build_day_grid(array $api, array $cfg, DateTimeZone $tz): array
{
    $slotMin = max(15, (int) $cfg['slot_minutes']);
    $now     = $api['currentTimestamp'];

    $today    = (new DateTimeImmutable("@$now"))->setTimezone($tz);
    $dayStart = $today->setTime((int) $cfg['day_start_hour'], 0)->getTimestamp();
    $dayEnd   = $today->setTime((int) $cfg['day_end_hour'], 0)->getTimestamp();

    $slotCount  = max(1, (int) (($dayEnd - $dayStart) / ($slotMin * 60)));
    $slotLabels = [];
    for ($i = 0; $i < $slotCount; $i++) {
        $ts           = $dayStart + $i * $slotMin * 60;
        $slotLabels[] = (new DateTimeImmutable("@$ts"))->setTimezone($tz)->format('H:i');
    }

    $nowIdx = (int) floor(($now - $dayStart) / ($slotMin * 60));

    // Build the occupancy map: [courtId][slotIdx] => resInfo|null
    $occupiedBy = [];
    foreach ($api['courts'] as $court) {
        $occupiedBy[$court['id']] = array_fill(0, $slotCount, null);
    }

    foreach ($api['reservations'] as $res) {
        $start = (int) $res['startTimestamp'];
        $end   = (int) $res['endTimestamp'];

        if ($end <= $dayStart || $start >= $dayEnd) {
            continue;
        }

        $startIdx = max(0, (int) floor(($start - $dayStart) / ($slotMin * 60)));
        $endIdx   = min($slotCount, (int) ceil(($end - $dayStart) / ($slotMin * 60)));
        $isLive   = $start <= $now && $now < $end;

        $startTxt = (new DateTimeImmutable("@$start"))->setTimezone($tz)->format('H:i');
        $endTxt   = (new DateTimeImmutable("@$end"))->setTimezone($tz)->format('H:i');

        $resInfo = [
            'type'     => $isLive ? 'live' : 'booked',
            'label'    => "{$startTxt}\u{2013}{$endTxt}",
            'startIdx' => $startIdx,
            'endIdx'   => $endIdx,
        ];

        foreach ((array) $res['courts'] as $courtId) {
            if (!isset($occupiedBy[$courtId])) {
                continue;
            }
            for ($i = $startIdx; $i < $endIdx; $i++) {
                $occupiedBy[$courtId][$i] = $resInfo;
            }
        }
    }

    // Turn the occupancy maps into contiguous blocks
    $columns = [];
    foreach ($api['courts'] as $court) {
        $cid    = $court['id'];
        $blocks = [];
        $i      = 0;

        while ($i < $slotCount) {
            $entry = $occupiedBy[$cid][$i];

            if ($entry === null) {
                $j = $i;
                while ($j < $slotCount && $occupiedBy[$cid][$j] === null) {
                    $j++;
                }
                $blocks[] = ['startIdx' => $i, 'endIdx' => $j, 'type' => 'free', 'label' => ''];
                $i = $j;
            } else {
                $j = $i;
                while ($j < $slotCount && $occupiedBy[$cid][$j] === $entry) {
                    $j++;
                }
                $blocks[] = [
                    'startIdx' => $i,
                    'endIdx'   => $j,
                    'type'     => $entry['type'],
                    'label'    => $entry['label'],
                ];
                $i = $j;
            }
        }

        $columns[$cid] = $blocks;
    }

    return [
        'slotLabels' => $slotLabels,
        'slotCount'  => $slotCount,
        'dayStart'   => $dayStart,
        'dayEnd'     => $dayEnd,
        'nowIdx'     => $nowIdx,
        'columns'    => $columns,
    ];
}

