<?php
// Loft RFID readers (ESP8266 / ESP32): registration by the Super Admin, lists
// for clubs and members, and the two calls the readers make themselves
// (device_rfid_scan for each tap, device_rfid_heartbeat every minute).
declare(strict_types=1);

function normalize_device_serial($serial): ?string
{
    $s = strtoupper(preg_replace('/[\s:.-]/', '', (string)($serial ?? '')));
    return preg_match('/^[0-9A-F]{12}$/', $s) ? $s : null;
}
function device_key_hash($key): string
{
    return hash('sha256', strtoupper(preg_replace('/[\s-]/', '', (string)($key ?? ''))));
}
function new_device_key(): string { return strtoupper(bin2hex(random_bytes(16))); }
function check_club_member(int $club, $member, string $message = "That member is not in the reader's club."): void
{
    if ($member !== null && !val('SELECT 1 FROM members WHERE id = ? AND club_id = ?', [(int)$member, $club])) fail($message);
}
// A reader is linked to a member's loft ("clock" mode) or not ("register" mode).
function device_mode($member): string { return $member === null ? 'register' : 'clock'; }
function device_row(array $d, bool $withClub = false): array
{
    $out = [
        'id' => (int)$d['id'], 'serial' => $d['serial'], 'name' => $d['name'],
        'member_id' => $d['member_id'] === null ? null : (int)$d['member_id'], 'member_name' => $d['member_name'],
        'mode' => $d['mode'], 'last_seen_at' => format_value($d['last_seen_at'], 'timestamptz'), 'last_tag' => $d['last_tag'],
        'created_at' => format_value($d['created_at'], 'timestamptz'), 'last_heartbeat_at' => format_value($d['last_heartbeat_at'], 'timestamptz'),
        'pending_taps' => (int)$d['pending_taps'], 'clock_source' => $d['clock_source'], 'is_online' => (bool)$d['is_online'],
    ];
    if ($withClub) $out = array_slice($out, 0, 3) + ['club_id' => (int)$d['club_id'], 'club_name' => $d['club_name']] + $out;
    return $out;
}
const DEVICE_LIST_SQL = "SELECT d.*, m.full_name AS member_name, c.name AS club_name,
                                COALESCE(d.last_heartbeat_at > UTC_TIMESTAMP(6) - INTERVAL 3 MINUTE, 0) AS is_online
                           FROM rfid_devices d JOIN clubs c ON c.id = d.club_id LEFT JOIN members m ON m.id = d.member_id";

// The reader's serial and key, checked on each call from a reader.
function authenticate_device(array $a): array
{
    $serial = normalize_device_serial($a['p_serial'] ?? null);
    $device = $serial ? row('SELECT * FROM rfid_devices WHERE serial = ? FOR UPDATE', [$serial]) : null;
    if (!$device) fail('This reader is not registered. Ask the Super Admin to register serial ' . ($serial ?? (string)($a['p_serial'] ?? '')) . '.', '42501', 403);
    if (!hash_equals($device['key_hash'], device_key_hash($a['p_key'] ?? ''))) fail('The device key is wrong. Ask the Super Admin for a new key.', '42501', 403);
    return $device;
}

function reader_functions(): array
{
    return [
        'admin_list_rfid_devices' => function ($a) {
            if (!can_manage_club_data()) deny('Club administrator or staff account required.');
            return array_map(fn($d) => device_row($d), rows(DEVICE_LIST_SQL . ' WHERE d.club_id = ? ORDER BY d.created_at DESC', [current_club_id()]));
        },
        'member_list_rfid_devices' => function ($a) {
            $member = current_member_id();
            if ($member === null) return [];
            return array_map(fn($d) => ['name' => $d['name'], 'serial' => $d['serial'], 'last_seen_at' => format_value($d['last_seen_at'], 'timestamptz')],
                rows('SELECT name, serial, last_seen_at FROM rfid_devices WHERE member_id = ? ORDER BY name', [$member]));
        },
        'superadmin_list_rfid_devices' => function ($a) {
            require_superadmin();
            return array_map(fn($d) => device_row($d, true), rows(DEVICE_LIST_SQL . ' ORDER BY d.created_at DESC'));
        },
        'superadmin_register_rfid_device' => function ($a) {
            require_superadmin();
            $serial = normalize_device_serial($a['p_serial'] ?? null);
            if ($serial === null) fail("Enter the reader's 12-character serial number, for example 5C:CF:7F:12:34:56.");
            $name = trim((string)($a['p_name'] ?? ''));
            if ($name === '') fail('Give the reader a name.');
            $club = (int)($a['p_club_id'] ?? 0);
            if (!val('SELECT 1 FROM clubs WHERE id = ?', [$club])) fail('Choose the club that will use this reader.');
            $member = isset($a['p_member_id']) && $a['p_member_id'] !== null ? (int)$a['p_member_id'] : null;
            check_club_member($club, $member);
            if (val('SELECT 1 FROM rfid_devices WHERE serial = ?', [$serial])) fail("Reader $serial is already registered.", '23505', 409);
            $key = new_device_key();
            q('INSERT INTO rfid_devices (club_id, serial, name, member_id, mode, key_hash, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$club, $serial, mb_substr($name, 0, 80), $member, device_mode($member), device_key_hash($key), uid()]);
            return ['id' => (int)db()->lastInsertId(), 'serial' => $serial, 'device_key' => $key];
        },
        'superadmin_update_rfid_device' => function ($a) {
            require_superadmin();
            $name = trim((string)($a['p_name'] ?? ''));
            if ($name === '') fail('Give the reader a name.');
            $club = (int)($a['p_club_id'] ?? 0);
            if (!val('SELECT 1 FROM clubs WHERE id = ?', [$club])) fail('Choose the club that will use this reader.');
            $d = row('SELECT * FROM rfid_devices WHERE id = ?', [(int)($a['p_id'] ?? 0)]);
            if (!$d) fail('Reader not found.');
            $member = (int)$d['club_id'] === $club ? $d['member_id'] : null;
            q('UPDATE rfid_devices SET name = ?, member_id = ?, club_id = ?, mode = ? WHERE id = ?', [mb_substr($name, 0, 80), $member, $club, device_mode($member), $d['id']]);
            return null;
        },
        'superadmin_set_rfid_device_member' => function ($a) {
            require_superadmin();
            $d = row('SELECT * FROM rfid_devices WHERE id = ?', [(int)($a['p_id'] ?? 0)]);
            if (!$d) fail('Reader not found.');
            $member = isset($a['p_member_id']) && $a['p_member_id'] !== null ? (int)$a['p_member_id'] : null;
            check_club_member((int)$d['club_id'], $member);
            q('UPDATE rfid_devices SET member_id = ?, mode = ? WHERE id = ?', [$member, device_mode($member), $d['id']]);
            return null;
        },
        'superadmin_reset_rfid_device_key' => function ($a) {
            require_superadmin();
            $d = row('SELECT * FROM rfid_devices WHERE id = ?', [(int)($a['p_id'] ?? 0)]);
            if (!$d) fail('Reader not found.');
            $key = new_device_key();
            q('UPDATE rfid_devices SET key_hash = ? WHERE id = ?', [device_key_hash($key), $d['id']]);
            return ['id' => (int)$d['id'], 'serial' => $d['serial'], 'device_key' => $key];
        },
        'superadmin_delete_rfid_device' => function ($a) {
            require_superadmin();
            if (!q('DELETE FROM rfid_devices WHERE id = ?', [(int)($a['p_id'] ?? 0)])->rowCount()) fail('Reader not found.');
            return null;
        },
        // Tags tapped on readers in "register" mode in the last 2 minutes (they
        // fill the open Race entries / Register NFC page).
        'rfid_recent_scans' => function ($a) {
            $super = is_superadmin();
            if (!$super && !can_manage_club_data()) deny('Club administrator, staff or Super Admin account required.');
            $after = isset($a['p_after_id']) && $a['p_after_id'] !== null ? (int)$a['p_after_id'] : null;
            return array_map(fn($r) => ['id' => (int)$r['id'], 'device_name' => $r['name'], 'tag_uid' => $r['tag_uid'], 'scanned_at' => format_value($r['scanned_at'], 'timestamptz')],
                rows("SELECT s.id, d.name, s.tag_uid, s.scanned_at FROM rfid_device_scans s JOIN rfid_devices d ON d.id = s.device_id
                       WHERE s.mode = 'register' AND (? OR d.club_id = ?) AND (? IS NULL OR s.id > ?) AND s.scanned_at > UTC_TIMESTAMP(6) - INTERVAL 2 MINUTE
                       ORDER BY s.id DESC LIMIT 20", [$super ? 1 : 0, current_club_id(), $after, $after]));
        },

        // ---- Called by the readers (serial + device key, no sign-in) ---------------------------
        'device_rfid_heartbeat' => function ($a) {
            return transaction(function () use ($a) {
                $d = authenticate_device($a);
                q('UPDATE rfid_devices SET last_heartbeat_at = ?, pending_taps = ?, clock_source = ?, firmware = ? WHERE id = ?', [
                    now_utc(), max(0, min((int)($a['p_pending'] ?? 0), 100000)),
                    isset($a['p_clock']) ? mb_substr((string)$a['p_clock'], 0, 20) : null,
                    isset($a['p_firmware']) ? mb_substr((string)$a['p_firmware'], 0, 40) : null, $d['id']]);
                return ['status' => 'online', 'mode' => $d['mode'], 'name' => $d['name'], 'server_time' => format_value(now_utc(), 'timestamptz')];
            });
        },
        'device_rfid_scan' => fn($a) => transaction(fn() => device_rfid_scan($a)),
    ];
}

function device_rfid_scan(array $a): array
{
    $d = authenticate_device($a);
    try { $uid = normalize_nfc_uid($a['p_tag'] ?? null); } catch (ApiError $e) { $uid = null; }
    if ($uid === null) fail('The tag ID could not be read. Tap the tag again.');
    $server = now_utc();
    $tap = $server; $source = 'server'; $note = '';
    $log = function (string $mode, string $result, string $at) use ($d, $uid) {
        q('INSERT INTO rfid_device_scans (device_id, tag_uid, mode, result, scanned_at) VALUES (?, ?, ?, ?, ?)', [$d['id'], $uid, $mode, mb_substr($result, 0, 200), $at]);
    };
    // Use the reader's tap time when it is believable.
    if (!empty($a['p_scanned_at'])) {
        $at = to_utc((string)$a['p_scanned_at']);
        $serverTs = strtotime($server . ' UTC'); $atTs = strtotime($at . ' UTC');
        if ($atTs > $serverTs + 120) {
            $note = ' Reader clock is ahead, so the server time was used.';
        } elseif ($atTs < $serverTs - 3 * 86400) {
            $result = ['status' => 'error', 'mode' => $d['mode'], 'tag' => $uid, 'message' => "Tap $uid is more than 3 days old and was not used. Clock it on the Clocking page if needed."];
            $log($d['mode'], $result['message'], $at);
            return $result;
        } else {
            $tap = min($at, $server);
            $source = 'reader';
        }
    }
    q('UPDATE rfid_devices SET last_seen_at = ?, last_heartbeat_at = ?, last_tag = ? WHERE id = ?', [$server, $server, $uid, $d['id']]);
    q('DELETE FROM rfid_device_scans WHERE scanned_at < UTC_TIMESTAMP(6) - INTERVAL 4 DAY');
    $tapTs = strtotime($tap . ' UTC'); $serverTs = strtotime($server . ' UTC');

    if ($d['mode'] === 'register') {
        $log('register', 'sent', $tap);
        return ['status' => 'sent', 'mode' => 'register', 'tag' => $uid, 'message' => $tapTs < $serverTs - 120
            ? "Tag $uid arrived late (tapped while offline), so it was not filled into any page."
            : "Tag $uid sent to the open Race entries or Register NFC page."];
    }
    $match = "e.nfc_tag = ? AND r.status = 'open' AND r.club_id = ? AND (? IS NULL OR e.member_id = ?)";
    $params = [$uid, $d['club_id'], $d['member_id'], $d['member_id']];
    $n = (int)val("SELECT COUNT(*) FROM race_entries e JOIN races r ON r.id = e.race_id WHERE $match", $params);
    if ($n !== 1) {
        $result = ['status' => 'error', 'mode' => 'clock', 'tag' => $uid, 'message' => $n === 0
            ? "Tag $uid is not on a pigeon in an open race" . ($d['member_id'] === null ? '.' : ' for this loft.')
            : "Tag $uid is entered in more than one open race. Clock it on the Clocking page."];
        $log('clock', $result['message'], $tap);
        return $result;
    }
    $entry = row("SELECT e.* FROM race_entries e JOIN races r ON r.id = e.race_id WHERE $match FOR UPDATE", $params);
    $race = row('SELECT * FROM races WHERE id = ?', [$entry['race_id']]);
    if ($race['release_at_utc'] === null || $tap <= $race['release_at_utc']) {
        $result = ['status' => 'error', 'mode' => 'clock', 'tag' => $uid, 'message' => $source === 'reader' && $race['release_at_utc'] !== null && $server > $race['release_at_utc']
            ? "Tag $uid was tapped before {$race['name']} was released." : "{$race['name']} has not started yet."];
    } elseif ($entry['nfc_expires_at'] !== null && $tap > $entry['nfc_expires_at']) {
        $result = ['status' => 'error', 'mode' => 'clock', 'tag' => $uid, 'message' => "The RFID tag on ring {$entry['pigeon_ring']} expired on "
            . ph_time($entry['nfc_expires_at'], 'M d, h:i A') . '. Clock this pigeon with its sticker.'];
    } else {
        $clock = row('SELECT * FROM clock_events WHERE race_entry_id = ?', [$entry['id']]);
        if ($clock) {
            // Another way reached AvilonX first: that receipt stays, this tap is not used.
            $how = $clock['source'] === 'barcode' ? ' with its sticker' : ($clock['source'] === 'admin_manual' ? ' by the club' : ($clock['time_source'] === 'reader' ? ' by a reader' : ' with its NFC tag'));
            $result = ['status' => 'duplicate', 'message' => "Ring {$entry['pigeon_ring']} was already clocked at " . ph_time($clock['received_at_utc'], 'M d, h:i:s A') . "$how. This tap was not used."];
        } else {
            q('INSERT INTO clock_events (race_entry_id, pigeon_ring, received_at_utc, server_received_at_utc, time_source, source, verification) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$entry['id'], $entry['pigeon_ring'], $tap, $server, $source, 'nfc', 'provisional']);
            $clock = row('SELECT * FROM clock_events WHERE id = ?', [db()->lastInsertId()]);
            audit('clock_events', 'insert', null, $clock);
            $late = $source === 'reader' && $tapTs < $serverTs - 60 ? ' at the time it was tapped (sent late).' : '.';
            $result = ['status' => 'accepted', 'message' => "Ring {$entry['pigeon_ring']} clocked for {$race['name']}$late$note"];
        }
        $result += ['mode' => 'clock', 'tag' => $uid, 'pigeon_ring' => $entry['pigeon_ring'], 'race' => $race['name'],
            'received_at_utc' => format_value($clock['received_at_utc'], 'timestamp'), 'time_source' => $clock['time_source']] + clock_receipt_details($entry, $clock);
    }
    $log('clock', $result['message'], $tap);
    return $result;
}
