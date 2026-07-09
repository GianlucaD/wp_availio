<?php
// includes/availio-api.php
// -----------------------------------------------------------------------------
// Availio (Fairgate FSA) API-Client – WordPress-Plugin-Version.
// Hält den Bearer-Token serverseitig; er wird nie an den Browser übergeben.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Holt Reservationen + Court-Liste von Availio.
 *
 * WICHTIG: Fairgate FSA liefert HTTP 500, wenn kein User-Agent-Header gesetzt ist.
 *          CURLOPT_USERAGENT muss deshalb immer explizit gesetzt werden.
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

    // WordPress-eigene HTTP-API bevorzugt; alternativ cURL als Fallback
    if (function_exists('wp_remote_get')) {
        return availio_fetch_wp($url, $cfg);
    }
    return availio_fetch_curl($url, $cfg);
}

/** Fetch via wp_remote_get (bevorzugt in WordPress-Kontext) */
function availio_fetch_wp(string $url, array $cfg): array
{
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
        return ['ok' => false, 'error' => 'Verbindungsfehler: ' . $response->get_error_message()];
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);

    return availio_parse_response((int) $status, $body, $cfg);
}

/** Fetch via cURL (Fallback ausserhalb von WordPress oder für Tests) */
function availio_fetch_curl(string $url, array $cfg): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL ist nicht verfügbar.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int) $cfg['http_timeout'],
        CURLOPT_USERAGENT      => 'availio-wp-dashboard/1.0',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['availio_access_key'],
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Verbindungsfehler: ' . $cerr];
    }

    return availio_parse_response($status, $body, $cfg);
}

/** Gemeinsame Response-Verarbeitung */
function availio_parse_response(int $status, string $body, array $cfg): array
{
    if ($status === 401) {
        return ['ok' => false, 'error' => 'Token ungültig oder abgelaufen (HTTP 401).'];
    }
    if ($status !== 200) {
        return ['ok' => false, 'error' => "API-Fehler HTTP {$status}."];
    }

    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['data'])) {
        return ['ok' => false, 'error' => 'API-Antwort konnte nicht geparst werden.'];
    }

    $data   = $json['data'];
    $courts = $data['club']['courts'] ?? [];

    // Optional: Court-Filter anwenden
    if (!empty($cfg['court_filter'])) {
        $allow  = array_flip($cfg['court_filter']);
        $courts = array_values(array_filter(
            $courts,
            static fn($c) => isset($allow[$c['id']])
        ));
    }

    // Stornierte Reservationen ausfiltern
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

// ── Tagesansicht-Grid ─────────────────────────────────────────────────────────

/**
 * Baut ein Grid für die Tagesansicht.
 *
 * Jede Reservation wird zu einem Block; freie Bereiche ebenfalls als Blöcke
 * (für row-span-Rendering ohne Lücken).
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

    // Belegungs-Map aufbauen: [courtId][slotIdx] => resInfo|null
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

    // Belegungs-Maps in zusammenhängende Blöcke umwandeln
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

// ── Wochenansicht-Daten ───────────────────────────────────────────────────────

/**
 * Bereitet Daten für die Wochenansicht auf.
 *
 * Gibt für jeden Tag der nächsten 7 Tage pro Court eine Liste
 * von Buchungen zurück.
 *
 * @return array{
 *     courts: list<array>,
 *     days: list<array{date: string, dayName: string, dateLabel: string, courts: array}>,
 * }
 */
function availio_build_week_data(array $api, array $cfg, DateTimeZone $tz, int $nextHours = 72): array
{
    $now      = $api['currentTimestamp'];
    $slotMin  = max(15, (int) $cfg['slot_minutes']);
    $courts   = $api['courts'];

    // Anzahl Tage aus dem API-Fenster berechnen (mindestens 1, höchstens 14)
    $daysToShow = max(1, min(14, (int) ceil($nextHours / 24)));

    $days = [];
    $todayBase = (new DateTimeImmutable("@$now"))->setTimezone($tz)->setTime(0, 0);

    $dayNames = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    for ($d = 0; $d < $daysToShow; $d++) {
        $dayDt    = $todayBase->modify("+{$d} days");
        $dayStart = $dayDt->setTime((int) $cfg['day_start_hour'], 0)->getTimestamp();
        $dayEnd   = $dayDt->setTime((int) $cfg['day_end_hour'], 0)->getTimestamp();
        $dateStr  = $dayDt->format('Y-m-d');

        // Buchungen für diesen Tag aufsammeln
        $courtBookings = [];
        foreach ($courts as $court) {
            $courtBookings[$court['id']] = [];
        }

        foreach ($api['reservations'] as $res) {
            $start = (int) $res['startTimestamp'];
            $end   = (int) $res['endTimestamp'];

            // Überschneidung mit diesem Tag?
            if ($end <= $dayStart || $start >= $dayEnd) {
                continue;
            }

            $isLive   = $start <= $now && $now < $end;
            $startTxt = (new DateTimeImmutable('@' . max($start, $dayStart)))->setTimezone($tz)->format('H:i');
            $endTxt   = (new DateTimeImmutable('@' . min($end, $dayEnd)))->setTimezone($tz)->format('H:i');

            $booking = [
                'type'  => $isLive ? 'live' : 'booked',
                'label' => "{$startTxt}\u{2013}{$endTxt}",
                'start' => $start,
            ];

            foreach ((array) $res['courts'] as $courtId) {
                if (isset($courtBookings[$courtId])) {
                    $courtBookings[$courtId][] = $booking;
                }
            }
        }

        // Buchungen pro Court nach Startzeit sortieren
        foreach ($courtBookings as &$bookingList) {
            usort($bookingList, static fn($a, $b) => $a['start'] <=> $b['start']);
        }
        unset($bookingList);

        $dow = (int) $dayDt->format('w');   // 0 = Sonntag

        $days[] = [
            'date'      => $dateStr,
            'dayName'   => $dayNames[$dow],
            'dateLabel' => $dayDt->format('d.m.'),
            'courts'    => $courtBookings,
        ];
    }

    return [
        'courts' => $courts,
        'days'   => $days,
    ];
}
