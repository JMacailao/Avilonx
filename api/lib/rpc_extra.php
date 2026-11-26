<?php
// Landing page (races today, club requests), season standings, members' loft
// reader status, and app error reports: from the Supabase version.
declare(strict_types=1);

function extra_functions(): array
{
    return [
        // ---- Landing page (no sign-in) -----------------------------------------------------
        'public_races_today' => function ($a) {
            $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            return array_map(fn($r) => [
                'club_name' => $r['club_name'], 'race_name' => $r['name'], 'release_location' => $r['release_location'],
                'release_at_utc' => format_value($r['release_at_utc'], 'timestamp'), 'status' => $r['status'], 'is_practice' => (bool)$r['is_practice'],
                'entries' => (int)$r['entries'], 'clocked' => (int)$r['clocked'],
                'best_speed' => $r['best_speed'] === null ? null : (float)$r['best_speed'],
            ], rows("SELECT c.name AS club_name, r.name, r.release_location, r.release_at_utc, r.status, r.is_practice,
                            (SELECT COUNT(*) FROM race_entries e WHERE e.race_id = r.id) AS entries,
                            (SELECT COUNT(*) FROM race_entries e JOIN clock_events k ON k.race_entry_id = e.id WHERE e.race_id = r.id AND k.verification <> 'rejected') AS clocked,
                            (SELECT MAX(e.distance_m * 60 / (TIMESTAMPDIFF(MICROSECOND, r.release_at_utc, k.received_at_utc) / 1000000))
                               FROM race_entries e JOIN clock_events k ON k.race_entry_id = e.id
                              WHERE e.race_id = r.id AND k.verification <> 'rejected' AND k.received_at_utc > r.release_at_utc) AS best_speed
                       FROM races r JOIN clubs c ON c.id = r.club_id
                      WHERE r.status <> 'draft' AND r.release_at_utc IS NOT NULL AND " . PH_DATE_SQL . " = ?
                      ORDER BY r.release_at_utc DESC LIMIT 12", [$today]));
        },
        'submit_club_request' => function ($a) {
            if (trim((string)($a['p_website'] ?? '')) !== '') return ['ok' => true];   // a bot filled the hidden field
            $name = trim((string)($a['p_contact_name'] ?? '')); $club = trim((string)($a['p_club_name'] ?? ''));
            $phone = mb_substr(trim((string)($a['p_phone'] ?? '')), 0, 40); $email = strtolower(mb_substr(trim((string)($a['p_email'] ?? '')), 0, 254));
            if (mb_strlen($name) < 2 || mb_strlen($club) < 2) fail("Enter your name and your club's name.");
            if (strlen(preg_replace('/\D/', '', $phone)) < 7 && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) fail('Enter a phone number or an email address so we can reach you.');
            if ((int)val('SELECT COUNT(*) FROM club_requests WHERE created_at > UTC_TIMESTAMP(6) - INTERVAL 1 HOUR') >= 20
                || (int)val("SELECT COUNT(*) FROM club_requests WHERE created_at > UTC_TIMESTAMP(6) - INTERVAL 1 DAY AND ((? <> '' AND phone = ?) OR (? <> '' AND email = ?))", [$phone, $phone, $email, $email]) >= 3) {
                fail('We already have your request. We will contact you soon.');
            }
            q('INSERT INTO club_requests (contact_name, club_name, phone, email, message) VALUES (?, ?, ?, ?, ?)',
                [mb_substr($name, 0, 120), mb_substr($club, 0, 180), $phone, $email, mb_substr(trim((string)($a['p_message'] ?? '')), 0, 1000)]);
            $id = (int)db()->lastInsertId();
            foreach (rows("SELECT id FROM app_profiles WHERE role = 'superadmin'") as $p) {
                push_queue($p['id'], 'New club request: ' . mb_substr($club, 0, 60), $name . ($phone !== '' ? " · $phone" : ''), 'pages/superadmin/clubs/index.html', "club-request-$id");
            }
            return ['ok' => true];
        },
        'superadmin_club_requests' => function ($a) {
            if (!is_superadmin()) deny('Super Admin only.');
            return array_map(fn($r) => format_row('club_requests', $r), rows('SELECT * FROM club_requests ORDER BY handled_at IS NOT NULL, created_at DESC LIMIT 100'));
        },
        'superadmin_handle_club_request' => function ($a) {
            if (!is_superadmin()) deny('Super Admin only.');
            $handled = !array_key_exists('p_handled', $a) || $a['p_handled'] === null ? true : (bool)$a['p_handled'];
            q('UPDATE club_requests SET handled_at = ? WHERE id = ?', [$handled ? now_utc() : null, (int)($a['p_id'] ?? 0)]);
            return null;
        },

        // ---- Members: their loft readers; clubs: season standings ------------------------------
        'member_reader_status' => function ($a) {
            $member = current_member_id();
            if ($member === null) return [];
            return array_map(fn($d) => ['name' => $d['name'], 'is_online' => (bool)$d['is_online'],
                'last_heartbeat_at' => format_value($d['last_heartbeat_at'], 'timestamptz'), 'last_seen_at' => format_value($d['last_seen_at'], 'timestamptz'),
                'pending_taps' => (int)$d['pending_taps']],
                rows('SELECT name, last_heartbeat_at, last_seen_at, pending_taps, COALESCE(last_heartbeat_at > UTC_TIMESTAMP(6) - INTERVAL 3 MINUTE, 0) AS is_online
                        FROM rfid_devices WHERE member_id = ? ORDER BY name', [$member]));
        },
        'season_standings' => fn($a) => season_standings(isset($a['p_year']) && $a['p_year'] !== null ? (int)$a['p_year'] : null),

        // ---- App error reports -----------------------------------------------------------------
        'log_client_error' => function ($a) {
            $message = mb_substr(trim((string)($a['p_message'] ?? '')), 0, 500);
            $page = mb_substr((string)($a['p_page'] ?? ''), 0, 200);
            if ($message === '') return null;
            $id = val('SELECT id FROM client_errors WHERE message = ? AND page = ? AND last_at > UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE ORDER BY id DESC LIMIT 1', [$message, $page]);
            if ($id) { q('UPDATE client_errors SET count = count + 1, last_at = ? WHERE id = ?', [now_utc(), $id]); return null; }
            if ((int)val('SELECT COUNT(*) FROM client_errors WHERE first_at > UTC_TIMESTAMP(6) - INTERVAL 1 HOUR') >= 300) return null;
            $p = profile();
            q('INSERT INTO client_errors (message, source, page, user_agent, app_version, user_id, club_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $message, mb_substr((string)($a['p_source'] ?? ''), 0, 300), $page, mb_substr((string)($a['p_user_agent'] ?? ''), 0, 300),
                mb_substr((string)($a['p_app_version'] ?? ''), 0, 40), $p['id'] ?? null, $p['club_id'] ?? null]);
            q('DELETE FROM client_errors WHERE last_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY');
            return null;
        },
        'superadmin_client_errors' => function ($a) {
            if (!is_superadmin()) deny('Super Admin only.');
            return array_map(fn($e) => [
                'id' => (int)$e['id'], 'message' => $e['message'], 'source' => $e['source'], 'page' => $e['page'], 'user_agent' => $e['user_agent'],
                'app_version' => $e['app_version'], 'username' => $e['username'], 'club_name' => $e['club_name'], 'count' => (int)$e['count'],
                'first_at' => format_value($e['first_at'], 'timestamptz'), 'last_at' => format_value($e['last_at'], 'timestamptz'),
            ], rows('SELECT e.*, p.username, c.name AS club_name FROM client_errors e LEFT JOIN app_profiles p ON p.id = e.user_id LEFT JOIN clubs c ON c.id = e.club_id ORDER BY e.last_at DESC LIMIT 100'));
        },
        'superadmin_clear_client_errors' => function ($a) {
            if (!is_superadmin()) deny('Super Admin only.');
            q('DELETE FROM client_errors');
            return null;
        },
    ];
}

// Season standings of the signed-in account's club: points per loft (11 − place,
// best bird per race), wins, races, birds clocked, best speed; ace pigeons (2+
// races, by average speed). Closed, non-practice races of the year; birds that
// had to show up count only when verified.
function season_standings(?int $year): array
{
    $club = current_club_id();
    if ($club === null) deny('Sign in with a club account.');
    $year = $year ?? (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y');
    $counted = rows("SELECT r.id AS race_id, m.id AS member_id, m.full_name, m.loft_name, m.member_no,
                            COALESCE(e.pigeon_ring, k.pigeon_ring) AS ring, e.distance_m, r.release_at_utc, k.received_at_utc
                       FROM races r JOIN race_entries e ON e.race_id = r.id JOIN members m ON m.id = e.member_id JOIN clock_events k ON k.race_entry_id = e.id
                      WHERE r.club_id = ? AND r.status = 'closed' AND r.is_practice = 0 AND r.release_at_utc IS NOT NULL
                        AND YEAR(DATE_ADD(r.release_at_utc, INTERVAL 8 HOUR)) = ? AND k.received_at_utc > r.release_at_utc
                        AND k.verification <> 'rejected' AND NOT (r.requires_show_up = 1 AND k.verification <> 'verified')", [$club, $year]);
    foreach ($counted as &$c) $c['speed'] = (float)$c['distance_m'] * 60 / seconds_between($c['release_at_utc'], $c['received_at_utc']);
    unset($c);
    // Place in each race (ties share a place).
    $byRace = [];
    foreach ($counted as $i => $c) $byRace[$c['race_id']][] = $i;
    foreach ($byRace as $list) {
        usort($list, fn($a, $b) => $counted[$b]['speed'] <=> $counted[$a]['speed']);
        $place = 0; $prev = null;
        foreach ($list as $pos => $i) { if ($prev === null || $counted[$i]['speed'] !== $prev) $place = $pos + 1; $counted[$i]['place'] = $place; $prev = $counted[$i]['speed']; }
    }
    $lofts = []; $best = [];
    foreach ($counted as $c) {
        $k = $c['race_id'] . ':' . $c['member_id'];
        if (!isset($best[$k]) || $c['place'] < $best[$k]['place']) $best[$k] = $c;
        $l = &$lofts[$c['member_id']];
        $l ??= ['full_name' => $c['full_name'], 'loft_name' => $c['loft_name'], 'member_no' => $c['member_no'], 'points' => 0, 'wins' => 0, 'races' => 0, 'birds_clocked' => 0, 'member_id' => (int)$c['member_id'], 'best_speed' => 0.0];
        $l['birds_clocked']++; $l['best_speed'] = max($l['best_speed'], $c['speed']);
        unset($l);
    }
    foreach ($best as $b) {
        $lofts[$b['member_id']]['points'] += max(0, 11 - $b['place']);
        $lofts[$b['member_id']]['wins'] += $b['place'] === 1 ? 1 : 0;
        $lofts[$b['member_id']]['races']++;
    }
    $lofts = array_values($lofts);
    foreach ($lofts as &$l) $l['best_speed'] = round($l['best_speed'], 3);
    unset($l);
    usort($lofts, fn($a, $b) => [$b['points'], $b['wins'], $b['best_speed']] <=> [$a['points'], $a['wins'], $a['best_speed']]);
    $aces = [];
    foreach ($counted as $c) {
        $k = $c['ring'] . '|' . $c['member_id'];
        $a = &$aces[$k];
        $a ??= ['ring' => $c['ring'], 'member_id' => (int)$c['member_id'], 'full_name' => $c['full_name'], 'loft_name' => $c['loft_name'], 'races' => 0, 'sum' => 0.0, 'best_speed' => 0.0, 'best_place' => PHP_INT_MAX];
        $a['races']++; $a['sum'] += $c['speed']; $a['best_speed'] = max($a['best_speed'], $c['speed']); $a['best_place'] = min($a['best_place'], $c['place']);
        unset($a);
    }
    $aces = array_values(array_filter($aces, fn($a) => $a['races'] >= 2));
    foreach ($aces as &$a) { $a['average_speed'] = round($a['sum'] / $a['races'], 3); $a['best_speed'] = round($a['best_speed'], 3); unset($a['sum']); }
    unset($a);
    usort($aces, fn($x, $y) => $y['average_speed'] <=> $x['average_speed']);
    $years = array_map('intval', array_column(rows("SELECT DISTINCT YEAR(DATE_ADD(release_at_utc, INTERVAL 8 HOUR)) AS y FROM races
                                                       WHERE club_id = ? AND status = 'closed' AND release_at_utc IS NOT NULL ORDER BY y DESC", [$club]), 'y'));
    return ['year' => $year, 'races' => count($byRace), 'lofts' => $lofts, 'aces' => array_slice($aces, 0, 20), 'years' => $years];
}
