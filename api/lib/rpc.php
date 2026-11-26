<?php
// Server functions called by the pages with supabase.rpc(name, args): the
// database functions of the Supabase version, in PHP.
declare(strict_types=1);

function rpc_functions(): array
{
    return [
        // ---- First start and sign-in -------------------------------------------------
        'has_admin_account' => fn($a) => (bool)val("SELECT 1 FROM app_profiles WHERE role = 'superadmin' LIMIT 1"),
        'create_superadmin' => function ($a) {
            return transaction(function () use ($a) {
                q('SELECT GET_LOCK(?, 10)', ['avilonx.create_superadmin']);
                try {
                    if (val("SELECT 1 FROM app_profiles WHERE role = 'superadmin'")) deny('The superadmin account has already been created.');
                    $username = normalize_username($a['p_username'] ?? '');
                    $id = create_auth_user($username, $a['p_password'] ?? null);
                    q("INSERT INTO app_profiles (id, username, role) VALUES (?, ?, 'superadmin')", [$id, $username]);
                    return ['status' => 'created', 'username' => $username];
                } finally {
                    q('SELECT RELEASE_LOCK(?)', ['avilonx.create_superadmin']);
                }
            });
        },

        // ---- Super Admin: clubs ---------------------------------------------------------
        'superadmin_list_clubs' => function ($a) {
            if (!is_superadmin()) return [];
            return array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => $r['name'], 'admin_username' => $r['admin_username'],
                'member_count' => (int)$r['member_count'], 'race_count' => (int)$r['race_count'],
                'created_at' => format_value($r['created_at'], 'timestamptz'),
            ], rows("SELECT c.id, c.name, c.created_at,
                        (SELECT p.username FROM app_profiles p WHERE p.club_id = c.id AND p.role = 'admin' ORDER BY p.created_at LIMIT 1) AS admin_username,
                        (SELECT COUNT(*) FROM members m WHERE m.club_id = c.id) AS member_count,
                        (SELECT COUNT(*) FROM races r WHERE r.club_id = c.id) AS race_count
                       FROM clubs c ORDER BY c.id DESC"));
        },
        'superadmin_create_club' => function ($a) {
            require_superadmin();
            $name = trim((string)($a['p_club_name'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 180) fail('Club name must be 2–180 characters.');
            return transaction(function () use ($a, $name) {
                $club = val('SELECT id FROM clubs WHERE name = ?', [$name]);
                if ($club) {
                    if (val("SELECT 1 FROM app_profiles WHERE club_id = ? AND role = 'admin'", [$club])) fail('A club with this name already has an account.', '23505', 409);
                } else {
                    q('INSERT INTO clubs (name) VALUES (?)', [$name]);
                    $club = (int)db()->lastInsertId();
                }
                $username = normalize_username($a['p_username'] ?? '');
                $id = create_auth_user($username, $a['p_password'] ?? null);
                q("INSERT INTO app_profiles (id, username, role, club_id) VALUES (?, ?, 'admin', ?)", [$id, $username, $club]);
                return ['status' => 'created', 'club_id' => (int)$club, 'username' => $username];
            });
        },
        'superadmin_delete_club' => function ($a) {
            require_superadmin();
            $club = (int)($a['p_club_id'] ?? 0);
            if (val('SELECT 1 FROM members WHERE club_id = ?', [$club]) || val('SELECT 1 FROM races WHERE club_id = ?', [$club]) || val('SELECT 1 FROM race_points WHERE club_id = ?', [$club])) {
                fail('This club still has members, races or race points. The club must delete them first.', '23503', 409);
            }
            return transaction(function () use ($club) {
                q('DELETE u FROM auth_users u JOIN app_profiles p ON p.id = u.id WHERE p.club_id = ?', [$club]);
                q('DELETE FROM clubs WHERE id = ?', [$club]);
                return ['status' => 'deleted'];
            });
        },
        'superadmin_set_club_password' => function ($a) {
            require_superadmin();
            $password = check_new_password($a['p_password'] ?? null);
            $user = val("SELECT id FROM app_profiles WHERE club_id = ? AND role = 'admin' ORDER BY created_at LIMIT 1", [(int)($a['p_club_id'] ?? 0)]);
            if (!$user) fail('This club has no account yet.');
            set_password($user, $password);
            return ['status' => 'updated'];
        },
        'superadmin_list_club_members' => function ($a) {
            require_superadmin();
            return array_map(fn($r) => ['id' => (int)$r['id'], 'member_no' => $r['member_no'], 'full_name' => $r['full_name']],
                rows('SELECT id, member_no, full_name FROM members WHERE club_id = ? ORDER BY member_no', [(int)($a['p_club_id'] ?? 0)]));
        },

        // ---- Club administrator: accounts ------------------------------------------------
        'admin_create_member_account' => function ($a) {
            require_app_admin();
            $member = (int)($a['p_member_id'] ?? 0);
            if (!val('SELECT 1 FROM members WHERE id = ? AND club_id = ?', [$member, current_club_id()])) fail('Member not found.');
            if (val('SELECT 1 FROM app_profiles WHERE member_id = ?', [$member])) fail('This member already has a login.', '23505', 409);
            return transaction(function () use ($a, $member) {
                $username = normalize_username($a['p_username'] ?? '');
                $id = create_auth_user($username, $a['p_password'] ?? null);
                q("INSERT INTO app_profiles (id, username, role, member_id, club_id) VALUES (?, ?, 'member', ?, ?)", [$id, $username, $member, current_club_id()]);
                return ['status' => 'created', 'username' => $username];
            });
        },
        'admin_set_member_password' => function ($a) {
            require_app_admin();
            $password = check_new_password($a['p_password'] ?? null);
            $user = val("SELECT id FROM app_profiles WHERE member_id = ? AND role = 'member' AND club_id = ?", [(int)($a['p_member_id'] ?? 0), current_club_id()]);
            if (!$user) fail('This member has no login yet.');
            set_password($user, $password);
            return ['status' => 'updated'];
        },
        'admin_update_user_credentials' => function ($a) {
            require_app_admin();
            $username = $a['p_username'] ?? null; $password = $a['p_password'] ?? null;
            if (empty($a['p_user_id']) || ($username === null && $password === null)) fail('Enter a username or a new password.');
            if ($username !== null) $username = normalize_username($username);
            if ($password !== null) $password = check_new_password($password);
            return transaction(function () use ($a, $username, $password) {
                $p = row("SELECT * FROM app_profiles WHERE id = ? AND club_id = ? AND role IN ('admin', 'staff', 'member') FOR UPDATE", [$a['p_user_id'], current_club_id()]);
                if (!$p) fail('User account not found in your club.');
                if ($username !== null) {
                    if (val('SELECT 1 FROM app_profiles WHERE username = ? AND id <> ?', [$username, $p['id']])) fail('That username is already in use.', '23505', 409);
                    q('UPDATE app_profiles SET username = ? WHERE id = ?', [$username, $p['id']]);
                }
                if ($password !== null) set_password($p['id'], $password);
                return ['status' => 'updated', 'username' => $username ?? $p['username']];
            });
        },
        'admin_create_team_account' => function ($a) {
            require_app_admin();
            $role = $a['p_role'] ?? null;
            if (!in_array($role, ['admin', 'staff'], true)) fail('Choose admin or staff.');
            return transaction(function () use ($a, $role) {
                $username = normalize_username($a['p_username'] ?? '');
                $id = create_auth_user($username, $a['p_password'] ?? null);
                q('INSERT INTO app_profiles (id, username, role, club_id, is_active) VALUES (?, ?, ?, ?, 1)', [$id, $username, $role, current_club_id()]);
                return ['status' => 'created', 'username' => $username, 'role' => $role];
            });
        },
        'admin_set_team_active' => function ($a) {
            require_app_admin();
            if (!array_key_exists('p_active', $a) || $a['p_active'] === null) fail('Choose whether the account should be active.');
            $active = (bool)$a['p_active'];
            return transaction(function () use ($a, $active) {
                $role = val("SELECT role FROM app_profiles WHERE id = ? AND club_id = ? AND role IN ('admin', 'staff') FOR UPDATE", [$a['p_user_id'] ?? '', current_club_id()]);
                if (!$role) fail('Team account not found.');
                if ($a['p_user_id'] === uid() && !$active) fail('You cannot disable your own account.');
                if ($role === 'admin' && !$active && (int)val("SELECT COUNT(*) FROM app_profiles WHERE club_id = ? AND role = 'admin' AND is_active = 1", [current_club_id()]) <= 1) {
                    fail('A club must keep at least one active administrator.');
                }
                q('UPDATE app_profiles SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $a['p_user_id']]);
                return ['status' => $active ? 'enabled' : 'disabled'];
            });
        },
        'admin_set_team_password' => function ($a) {
            require_app_admin();
            $password = check_new_password($a['p_password'] ?? null);
            $user = val("SELECT id FROM app_profiles WHERE id = ? AND club_id = ? AND role IN ('admin', 'staff')", [$a['p_user_id'] ?? '', current_club_id()]);
            if (!$user) fail('Team account not found.');
            set_password($user, $password);
            return ['status' => 'updated'];
        },

        // ---- Club settings ----------------------------------------------------------------
        'admin_update_club_settings' => function ($a) {
            if (!is_app_admin() || !current_club_id()) deny('Only the club administrator can change the club settings.');
            $rule = $a['p_rfid_entry_sticker'] ?? null;
            if (!in_array($rule, ['required', 'optional'], true)) fail('Choose required or optional.');
            q('UPDATE clubs SET rfid_entry_sticker = ? WHERE id = ?', [$rule, current_club_id()]);
            return ['rfid_entry_sticker' => $rule];
        },
        'admin_update_form_settings' => function ($a) {
            if (!is_app_admin() || !current_club_id()) deny('Only the club administrator can change the club settings.');
            $m = (int)($a['p_member_boxes'] ?? 0); $p = (int)($a['p_prefix_boxes'] ?? -1); $n = (int)($a['p_number_boxes'] ?? 0);
            if ($m < 1 || $m > 8 || $p < 0 || $p > 8 || $n < 1 || $n > 10) fail('Member ID: 1 to 8 boxes. Ring letters: 0 to 8. Ring numbers: 1 to 10.');
            q('UPDATE clubs SET form_member_boxes = ?, form_prefix_boxes = ?, form_number_boxes = ? WHERE id = ?', [$m, $p, $n, current_club_id()]);
            return ['form_member_boxes' => $m, 'form_prefix_boxes' => $p, 'form_number_boxes' => $n];
        },
        'admin_set_single_device' => function ($a) {
            if (!is_app_admin() || !current_club_id()) deny('Only the club administrator can change the club settings.');
            $on = (bool)($a['p_on'] ?? false);
            q('UPDATE clubs SET single_device = ? WHERE id = ?', [$on ? 1 : 0, current_club_id()]);
            return ['single_device' => $on];
        },

        'admin_set_club_logo' => function ($a) {
            $club = current_club_id();
            if (!is_app_admin() || $club === null) deny('Only the club administrator can change the club logo.');
            $path = $a['p_path'] ?? null;
            if ($path !== null && (!str_starts_with((string)$path, "$club/") || str_contains((string)$path, '..') || strlen((string)$path) > 200)) {
                fail("The logo was not saved in your club's folder. Try again.");
            }
            $old = val('SELECT logo_path FROM clubs WHERE id = ?', [$club]);
            q('UPDATE clubs SET logo_path = ? WHERE id = ?', [$path, $club]);
            return ['logo_path' => $path, 'replaced_path' => $old !== $path ? $old : null];
        },
        'member_save_clock_video' => function ($a) {
            $member = current_member_id();
            if ($member === null) deny('Member account required.');
            $clockId = (int)($a['p_clock_id'] ?? 0);
            $club = val('SELECT r.club_id FROM clock_events c JOIN race_entries e ON e.id = c.race_entry_id JOIN races r ON r.id = e.race_id WHERE c.id = ? AND e.member_id = ?', [$clockId, $member]);
            if ($club === null) deny('This clocking is not one of your pigeons.');
            $path = (string)($a['p_path'] ?? '');
            if (!str_starts_with($path, "$club/$member/$clockId/") || str_contains($path, '..')) fail('The video was not saved in your folder. Try again.');
            $duration = (float)($a['p_duration'] ?? 0); $size = (int)($a['p_size'] ?? 0);
            if ($duration <= 0 || $duration > 31) fail('The video must be 30 seconds or shorter.');
            if ($size <= 0 || $size > 52428800) fail('The video must be 50 MB or smaller.');
            if (!is_file(storage_path('clock-videos', $path))) fail('The video upload did not finish. Try again.');
            $old = val('SELECT storage_path FROM clock_videos WHERE clock_event_id = ?', [$clockId]);
            q('INSERT INTO clock_videos (clock_event_id, member_id, club_id, storage_path, duration_seconds, size_bytes) VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE storage_path = VALUES(storage_path), duration_seconds = VALUES(duration_seconds), size_bytes = VALUES(size_bytes), created_at = UTC_TIMESTAMP(6)',
                [$clockId, $member, $club, $path, round($duration, 2), $size]);
            return ['status' => 'saved', 'replaced_path' => $old !== null && $old !== $path ? $old : null];
        },

        // ---- One device per account ----------------------------------------------------------
        'claim_account_device' => function ($a) {
            $p = require_signed_in();
            if (!single_device_applies()) return ['status' => 'ok'];
            $device = substr((string)($a['p_device_id'] ?? ''), 0, 80);
            if (strlen($device) < 8) fail('Bad device id.');
            $row = row('SELECT * FROM account_devices WHERE user_id = ?', [$p['id']]);
            if ($row && $row['device_id'] !== $device && empty($a['p_force'])) {
                return ['status' => 'other_device', 'device_label' => $row['device_label'], 'last_seen_at' => format_value($row['last_seen_at'], 'timestamptz')];
            }
            $now = now_utc();
            q('INSERT INTO account_devices (user_id, device_id, device_label, signed_in_at, last_seen_at) VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE signed_in_at = IF(device_id = VALUES(device_id), signed_in_at, VALUES(signed_in_at)),
                 device_id = VALUES(device_id), device_label = VALUES(device_label), last_seen_at = VALUES(last_seen_at)',
                [$p['id'], $device, mb_substr((string)($a['p_device_label'] ?? ''), 0, 80), $now, $now]);
            return ['status' => 'ok'];
        },
        'check_account_device' => function ($a) {
            $p = profile();
            if (!$p || !single_device_applies()) return ['status' => 'ok'];
            $row = row('SELECT * FROM account_devices WHERE user_id = ?', [$p['id']]);
            if (!$row) return ['status' => 'unclaimed'];
            if ($row['device_id'] !== (string)($a['p_device_id'] ?? '')) return ['status' => 'replaced', 'device_label' => $row['device_label']];
            q('UPDATE account_devices SET last_seen_at = ? WHERE user_id = ?', [now_utc(), $p['id']]);
            return ['status' => 'ok'];
        },
        'release_account_device' => function ($a) {
            if ($p = profile()) q('DELETE FROM account_devices WHERE user_id = ? AND device_id = ?', [$p['id'], (string)($a['p_device_id'] ?? '')]);
            return null;
        },

        // ---- Public results (no sign-in) ----------------------------------------------------
        'public_result_clubs' => fn($a) => array_map(fn($r) => ['club_id' => (int)$r['id'], 'club_name' => $r['name']], rows('SELECT id, name FROM clubs ORDER BY name')),
        'public_race_results' => fn($a) => public_race_results((int)($a['p_club_id'] ?? 0), (string)($a['p_race_date'] ?? '')),
        'public_race_summaries' => fn($a) => public_race_summaries((int)($a['p_club_id'] ?? 0), (string)($a['p_race_date'] ?? '')),

        // ---- Setup checklist (Super Admin): the PHP database is created whole. -----------------
        'setup_status' => function ($a) {
            $parts = ['multi-club', 'account-credentials', 'public-results', 'sticker-barcodes', 'race-release-time', 'race-schedule-dates', 'nfc-tags', 'rfid-devices',
                'entry-remarks', 'bird-bonding', 'show-up', 'rfid-tag-in-use', 'bird-ring-only', 'race-tag-expiry', 'club-settings', 'account-devices', 'password-reset',
                'telegram', 'race-point-recalc', 'clock-videos', 'club-logo', 'race-day', 'realtime'];
            return array_fill_keys($parts, true);
        },
    ] + password_reset_functions() + clocking_functions() + reader_functions();
}

function single_device_applies(): bool
{
    $p = profile();
    if (!$p || $p['role'] === 'superadmin' || !$p['club_id']) return false;
    return (bool)val('SELECT single_device FROM clubs WHERE id = ?', [$p['club_id']]);
}

// Race date in Philippine time (UTC+8), as in the Supabase version.
const PH_DATE_SQL = "DATE(DATE_ADD(r.release_at_utc, INTERVAL 8 HOUR))";

function public_race_results(int $club, string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Choose a race date.');
    $list = rows("SELECT r.id AS race_id, r.name AS race_name, m.loft_name, e.distance_m, COALESCE(e.pigeon_ring, c.pigeon_ring) AS ring, e.remarks,
                         COALESCE(e.barcode, '') AS barcode, c.received_at_utc, r.release_at_utc, r.status, c.verification, r.requires_show_up
                    FROM races r
                    JOIN race_entries e ON e.race_id = r.id
                    JOIN members m ON m.id = e.member_id AND m.club_id = r.club_id
                    JOIN clock_events c ON c.race_entry_id = e.id
                   WHERE r.club_id = ? AND " . PH_DATE_SQL . " = ? AND c.verification <> 'rejected' AND r.is_practice = 0", [$club, $date]);
    $out = [];
    foreach ($list as $x) {
        $remarks = json_decode((string)$x['remarks'], true) ?: [];
        $flight = null; $speed = null;
        if ($x['release_at_utc'] !== null) {
            $seconds = seconds_between($x['release_at_utc'], $x['received_at_utc']);
            if ($seconds > 0) { $flight = $seconds; $speed = (float)$x['distance_m'] * 60 / $seconds; }
        }
        $dq = $x['verification'] === 'provisional' && $x['status'] === 'closed' && (int)$x['requires_show_up'] === 1;
        $out[] = [
            '_race_id' => (int)$x['race_id'],
            'race_name' => $x['race_name'], 'loft_name' => $x['loft_name'], 'distance_m' => (float)$x['distance_m'],
            'pigeon_ring' => $x['ring'] . ($remarks ? ' ' . implode('/', $remarks) : ''), 'barcode' => $x['barcode'],
            'arrival_at_utc' => format_value($x['received_at_utc'], 'timestamp'), 'flight_seconds' => $flight, 'speed_m_per_min' => $speed,
            'rank' => null, 'race_status' => $x['status'], 'verification' => $x['verification'], 'disqualified' => $dq, 'show_up_required' => (bool)$x['requires_show_up'],
        ];
    }
    // Rank by speed within each race (ties share a rank), disqualified birds unranked.
    $byRace = [];
    foreach ($out as $i => $r) if ($r['speed_m_per_min'] !== null && !$r['disqualified']) $byRace[$r['_race_id']][] = $i;
    foreach ($byRace as $indexes) {
        usort($indexes, fn($a, $b) => $out[$b]['speed_m_per_min'] <=> $out[$a]['speed_m_per_min']);
        $rank = 0; $prev = null;
        foreach ($indexes as $pos => $i) {
            if ($prev === null || $out[$i]['speed_m_per_min'] !== $prev) $rank = $pos + 1;
            $out[$i]['rank'] = $rank; $prev = $out[$i]['speed_m_per_min'];
        }
    }
    usort($out, fn($a, $b) => [$a['race_name'], $a['disqualified'], $a['speed_m_per_min'] === null, -($a['speed_m_per_min'] ?? 0), $a['loft_name'], $a['pigeon_ring']]
                           <=> [$b['race_name'], $b['disqualified'], $b['speed_m_per_min'] === null, -($b['speed_m_per_min'] ?? 0), $b['loft_name'], $b['pigeon_ring']]);
    return array_map(function ($r) { unset($r['_race_id']); return $r; }, $out);
}
function public_race_summaries(int $club, string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Choose a race date.');
    return array_map(fn($r) => [
        'race_name' => $r['name'], 'release_location' => $r['release_location'],
        'release_latitude' => (float)$r['release_latitude'], 'release_longitude' => (float)$r['release_longitude'],
        'release_at_utc' => format_value($r['release_at_utc'], 'timestamp'), 'race_status' => $r['status'],
        'birds_entered' => (int)$r['entered'], 'birds_clocked' => (int)$r['clocked'],
    ], rows("SELECT r.name, r.release_location, r.release_latitude, r.release_longitude, r.release_at_utc, r.status,
                    (SELECT COUNT(*) FROM race_entries e WHERE e.race_id = r.id) AS entered,
                    (SELECT COUNT(DISTINCT e.id) FROM race_entries e JOIN clock_events c ON c.race_entry_id = e.id WHERE e.race_id = r.id AND c.verification <> 'rejected') AS clocked
               FROM races r WHERE r.club_id = ? AND " . PH_DATE_SQL . " = ? AND r.is_practice = 0 ORDER BY r.name", [$club, $date]));
}
function seconds_between(string $from, string $to): float
{
    $a = new DateTimeImmutable($from, new DateTimeZone('UTC'));
    $b = new DateTimeImmutable($to, new DateTimeZone('UTC'));
    return (float)$b->format('U.u') - (float)$a->format('U.u');
}
