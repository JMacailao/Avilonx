<?php
// RFID / NFC tags, stickers, and clocking (member sticker code, member NFC tap,
// club clocking, show-up), from the Supabase version.
declare(strict_types=1);

function clocking_functions(): array
{
    return [
        // ---- Super Admin: RFID / NFC tags --------------------------------------------------
        'superadmin_register_nfc_tag' => function ($a) {
            require_superadmin();
            $uid = normalize_nfc_uid($a['p_tag'] ?? null);
            if ($uid === null) fail('Tap an NFC tag first.');
            if (val('SELECT 1 FROM nfc_tags WHERE tag_uid = ?', [$uid])) fail('That NFC tag is already registered.', '23505', 409);
            q("INSERT INTO nfc_tags (tag_uid, source, created_by) VALUES (?, 'manual', ?)", [$uid, uid()]);
            return $uid;
        },
        'superadmin_delete_nfc_tag' => function ($a) {
            require_superadmin();
            $uid = normalize_nfc_uid($a['p_tag'] ?? null);
            if ($uid === null) fail('Choose an NFC tag to delete.');
            $races = (int)val("SELECT COUNT(DISTINCT r.id) FROM race_entries e JOIN races r ON r.id = e.race_id WHERE e.nfc_tag = ? AND r.status <> 'closed'", [$uid]);
            if ($races > 0) fail("This tag is on a pigeon in $races draft or open race(s). Remove it from those race entries, or close the races, before deleting it.", '55000');
            if (!q('DELETE FROM nfc_tags WHERE tag_uid = ?', [$uid])->rowCount()) fail('That NFC tag is not registered.');
            return $uid;
        },

        // ---- Super Admin: stickers ------------------------------------------------------------
        'superadmin_register_sticker' => function ($a) {
            require_superadmin();
            $number = normalize_sticker_code($a['p_barcode_number'] ?? null);
            $scratch = normalize_sticker_code($a['p_scratch_code'] ?? null);
            if (!preg_match('/^[0-9]{8}$/', (string)$number)) fail('Sticker numbers are 8 digits: a 6-digit serial and the 2-digit year, for example 00012326.');
            if ($scratch !== null) {
                if (!preg_match('/^[0-9]{6}$/', $scratch)) fail('Secret codes are 6 digits, for example 482715.');
                if ($scratch === $number) fail('The secret code must be different from the sticker number.');
            }
            return transaction(function () use ($number, $scratch) {
                $existing = row('SELECT * FROM barcode_stickers WHERE barcode_number = ? FOR UPDATE', [$number]);
                if ($existing) {
                    if (!(int)$existing['is_registered']) {
                        if ($scratch !== null && $existing['scratch_code'] !== $scratch) fail("That secret code does not match the generated sticker $number. Check the sticker, or leave the secret code blank.");
                        q('UPDATE barcode_stickers SET is_registered = 1 WHERE barcode_number = ?', [$number]);
                        return $number;
                    }
                    if ($scratch !== null && $existing['scratch_code'] === null) {
                        q('UPDATE barcode_stickers SET scratch_code = ? WHERE barcode_number = ?', [$scratch, $number]);
                        return $number;
                    }
                    fail("Sticker $number is already registered this season.", '23505', 409);
                }
                if ($scratch === null) fail('Enter the secret code (the number in the sticker\'s barcode).');
                q("INSERT INTO barcode_stickers (barcode_number, scratch_code, source, created_by) VALUES (?, ?, 'manual', ?)", [$number, $scratch, uid()]);
                return $number;
            });
        },
        'superadmin_generate_sticker_batch' => function ($a) {
            require_superadmin();
            $quantity = (int)($a['p_quantity'] ?? 0);
            if ($quantity < 1 || $quantity > 500) fail('Choose a batch quantity from 1 to 500.');
            $register = !array_key_exists('p_register', $a) || $a['p_register'] === null ? true : (bool)$a['p_register'];
            return transaction(function () use ($quantity, $register) {
                q('SELECT GET_LOCK(?, 30)', ['avilonx.stickers']);
                try {
                    $year = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('y');
                    $serial = (int)val("SELECT COALESCE(MAX(CAST(SUBSTRING(n, 1, 6) AS UNSIGNED)), 0) FROM (
                                          SELECT barcode_number AS n FROM barcode_stickers UNION ALL SELECT barcode_number FROM barcode_stickers_archive) x
                                        WHERE n REGEXP ?", ['^[0-9]{6}' . $year . '$']);
                    if ($serial + $quantity > 999999) fail('Only ' . (999999 - $serial) . ' sticker numbers are left for this year.');
                    $out = [];
                    for ($i = 1; $i <= $quantity; $i++) {
                        $number = str_pad((string)($serial + $i), 6, '0', STR_PAD_LEFT) . $year;
                        do { $scratch = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
                        while ($scratch === $number || val('SELECT 1 FROM barcode_stickers WHERE scratch_code = ?', [$scratch]));
                        q("INSERT INTO barcode_stickers (barcode_number, scratch_code, source, created_by, is_registered) VALUES (?, ?, 'generated', ?, ?)", [$number, $scratch, uid(), $register ? 1 : 0]);
                        $out[] = ['barcode_number' => $number, 'scratch_code' => $scratch, 'registered' => $register];
                    }
                    return $out;
                } finally {
                    q('SELECT RELEASE_LOCK(?)', ['avilonx.stickers']);
                }
            });
        },
        'superadmin_start_sticker_season' => function ($a) {
            require_superadmin();
            return transaction(function () {
                $races = (int)val("SELECT COUNT(DISTINCT r.id) FROM race_entries e JOIN races r ON r.id = e.race_id JOIN barcode_stickers s ON s.barcode_number = e.barcode WHERE r.status <> 'closed'");
                if ($races > 0) fail("$races draft or open race(s) still use stickers. Clubs must close those races before a new sticker season starts.", '55000');
                $n = q('INSERT INTO barcode_stickers_archive (barcode_number, scratch_code, source, is_registered, created_by, created_at, archived_by)
                        SELECT barcode_number, scratch_code, source, is_registered, created_by, created_at, ? FROM barcode_stickers', [uid()])->rowCount();
                q('DELETE FROM barcode_stickers');
                return $n;
            });
        },

        // ---- Clocking --------------------------------------------------------------------------
        'submit_member_clock' => function ($a) {
            $member = current_member_id();
            if ($member === null) deny('Member account required.');
            $raceId = (int)($a['p_race_id'] ?? 0);
            $code = normalize_sticker_code($a['p_barcode'] ?? null);
            if ($raceId < 1 || $code === null || strlen($code) > 120) fail('Enter a valid race and code.');
            return transaction(function () use ($member, $raceId, $code) {
                $race = row('SELECT r.* FROM races r WHERE r.id = ? AND EXISTS (SELECT 1 FROM race_entries e WHERE e.race_id = r.id AND e.member_id = ?)', [$raceId, $member]);
                if (!$race) fail('You have no pigeons entered in this race.');
                if ($race['status'] !== 'open') fail('This race is not open for clocking.');
                $now = now_utc();
                if ($race['release_at_utc'] === null || $now <= $race['release_at_utc']) fail('This race has not started yet.');
                $limit = 10;
                $failed = (int)(val('SELECT failed FROM member_clock_attempts WHERE member_id = ? AND race_id = ? FOR UPDATE', [$member, $raceId]) ?? 0);
                if ($failed >= $limit) return ['status' => 'locked', 'message' => 'Too many wrong codes for this race. Ask your club to record this clocking.'];
                $entry = row('SELECT e.* FROM race_entries e LEFT JOIN barcode_stickers s ON s.barcode_number = e.barcode
                               WHERE e.race_id = ? AND e.member_id = ? AND (s.scratch_code = ? OR (s.barcode_number IS NOT NULL AND s.scratch_code IS NULL AND e.barcode = ?))
                               ORDER BY (s.scratch_code = ?) DESC, e.id LIMIT 1 FOR UPDATE', [$raceId, $member, $code, $code, $code]);
                if (!$entry) {
                    if (val('SELECT 1 FROM race_entries e JOIN barcode_stickers s ON s.barcode_number = e.barcode WHERE e.race_id = ? AND e.member_id = ? AND e.barcode = ? AND s.scratch_code IS NOT NULL', [$raceId, $member, $code])) {
                        return ['status' => 'rejected', 'message' => 'That is the reference barcode. Scan the clocking barcode on the right-hand piece of the sticker.', 'tries_left' => max($limit - $failed, 0)];
                    }
                    q('INSERT INTO member_clock_attempts (member_id, race_id, failed, last_failed_at) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE failed = failed + 1, last_failed_at = VALUES(last_failed_at)', [$member, $raceId, now_utc()]);
                    $failed = (int)val('SELECT failed FROM member_clock_attempts WHERE member_id = ? AND race_id = ?', [$member, $raceId]);
                    $message = 'This code does not match a sticker registered to your account for this race.'
                        . ($failed >= $limit ? ' Too many wrong codes: ask your club to record this clocking.' : ' ' . ($limit - $failed) . ' tries left.');
                    return ['status' => 'rejected', 'message' => $message, 'tries_left' => max($limit - $failed, 0)];
                }
                $clock = row('SELECT * FROM clock_events WHERE race_entry_id = ?', [$entry['id']]);
                if ($clock) {
                    return ['status' => 'duplicate', 'message' => 'This pigeon already has a receipt.', 'receipt_id' => (int)$clock['id'],
                        'received_at_utc' => format_value($clock['received_at_utc'], 'timestamp'), 'pigeon_ring' => $entry['pigeon_ring'], 'barcode' => $entry['barcode']]
                        + clock_receipt_details($entry, $clock);
                }
                $clock = new_clock($entry, $now, 'barcode');
                return ['status' => 'accepted', 'message' => "Clocking received for sticker {$entry['barcode']} and marked provisional.", 'receipt_id' => (int)$clock['id'],
                    'received_at_utc' => format_value($clock['received_at_utc'], 'timestamp'), 'pigeon_ring' => $entry['pigeon_ring'], 'barcode' => $entry['barcode']]
                    + clock_receipt_details($entry, $clock);
            });
        },
        'submit_member_nfc_clock' => function ($a) {
            $member = current_member_id();
            if ($member === null) deny('Member account required.');
            $uid = normalize_nfc_uid($a['p_tag'] ?? null);
            if ($uid === null) fail('Tap an NFC tag first.');
            $raceId = isset($a['p_race_id']) && $a['p_race_id'] !== null ? (int)$a['p_race_id'] : null;
            return transaction(function () use ($member, $uid, $raceId) {
                $match = "e.member_id = ? AND e.nfc_tag = ? AND ((? IS NULL AND r.status = 'open') OR e.race_id = ?)";
                $params = [$member, $uid, $raceId, $raceId];
                $n = (int)val("SELECT COUNT(*) FROM race_entries e JOIN races r ON r.id = e.race_id WHERE $match", $params);
                if ($n === 0) fail('This NFC tag is not on any of your pigeons in an open race.');
                if ($n > 1) fail('This tag is entered in more than one open race. Choose the race, then tap again.');
                $entry = row("SELECT e.* FROM race_entries e JOIN races r ON r.id = e.race_id WHERE $match FOR UPDATE", $params);
                $race = row('SELECT * FROM races WHERE id = ?', [$entry['race_id']]);
                if ($race['status'] !== 'open') fail('This race is not open for clocking.');
                $now = now_utc();
                if ($race['release_at_utc'] === null || $now <= $race['release_at_utc']) fail('This race has not started yet.');
                if ($entry['nfc_expires_at'] !== null && $now > $entry['nfc_expires_at']) {
                    fail("The RFID tag on ring {$entry['pigeon_ring']} expired on " . ph_time($entry['nfc_expires_at'], 'M d, Y h:i A') . '. Clock this pigeon with its sticker.');
                }
                $clock = row('SELECT * FROM clock_events WHERE race_entry_id = ?', [$entry['id']]);
                if ($clock) {
                    return ['status' => 'duplicate', 'message' => "Ring {$entry['pigeon_ring']} already has a receipt.", 'receipt_id' => (int)$clock['id'],
                        'received_at_utc' => format_value($clock['received_at_utc'], 'timestamp'), 'pigeon_ring' => $entry['pigeon_ring'], 'race' => $race['name']]
                        + clock_receipt_details($entry, $clock);
                }
                $clock = new_clock($entry, $now, 'nfc');
                return ['status' => 'accepted', 'message' => "Ring {$entry['pigeon_ring']} clocked for {$race['name']}. Marked provisional.", 'receipt_id' => (int)$clock['id'],
                    'received_at_utc' => format_value($clock['received_at_utc'], 'timestamp'), 'pigeon_ring' => $entry['pigeon_ring'], 'race' => $race['name']]
                    + clock_receipt_details($entry, $clock);
            });
        },
        'admin_record_clock' => function ($a) {
            if (!can_manage_club_data()) deny('Club administrator or staff account required.');
            return transaction(function () use ($a) {
                $entry = row('SELECT * FROM race_entries WHERE id = ? FOR UPDATE', [(int)($a['p_entry_id'] ?? 0)]);
                if (!$entry) fail('Race entry not found.');
                $race = row('SELECT * FROM races WHERE id = ?', [$entry['race_id']]);
                if ((string)$race['club_id'] !== (string)current_club_id()) fail('Race entry not found.');
                if ($race['status'] !== 'open') fail('This race is not open for clocking.');
                $now = now_utc();
                if ($race['release_at_utc'] !== null && $now <= $race['release_at_utc']) fail('This race has not started yet.');
                $clock = row('SELECT * FROM clock_events WHERE race_entry_id = ?', [$entry['id']]);
                if ($clock) return ['status' => 'duplicate', 'message' => 'This pigeon already has a receipt.', 'receipt_id' => (int)$clock['id']];
                $clock = new_clock($entry, $now, 'admin_manual');
                return ['status' => 'accepted', 'message' => 'Clocking received and marked provisional.', 'receipt_id' => (int)$clock['id'], 'received_at_utc' => format_value($clock['received_at_utc'], 'timestamp')];
            });
        },
        'admin_show_up_tap' => function ($a) {
            if (!can_manage_club_data()) deny('Club administrator or staff account required.');
            try { $uid = normalize_nfc_uid($a['p_tag'] ?? null); } catch (ApiError $e) { $uid = null; }
            if ($uid === null) fail('The tag ID could not be read. Tap the tag again.');
            $raceId = isset($a['p_race_id']) && $a['p_race_id'] !== null ? (int)$a['p_race_id'] : null;
            return transaction(function () use ($uid, $raceId, $a) {
                $entry = row('SELECT e.* FROM race_entries e JOIN races r ON r.id = e.race_id
                               WHERE e.nfc_tag = ? AND r.club_id = ? AND (? IS NULL OR e.race_id = ?)
                               ORDER BY r.release_at_utc IS NULL, r.release_at_utc DESC, e.id DESC LIMIT 1', [$uid, current_club_id(), $raceId, $raceId]);
                if (!$entry) return ['status' => 'error', 'message' => "RFID tag $uid is not on a pigeon in " . ($raceId === null ? 'any race of this club.' : 'this race.')];
                $race = row('SELECT * FROM races WHERE id = ?', [$entry['race_id']]);
                $clock = row('SELECT * FROM clock_events WHERE race_entry_id = ? FOR UPDATE', [$entry['id']]);
                $base = ['entry_id' => (int)$entry['id'], 'pigeon_ring' => $entry['pigeon_ring'], 'race' => $race['name']];
                if (!$clock) return ['status' => 'error'] + $base + ['message' => "Ring {$entry['pigeon_ring']} has not been clocked in {$race['name']}, so there is no result to verify."];
                if ($clock['verification'] === 'verified') {
                    $when = $clock['shown_up_at'] !== null ? ' at ' . ph_time($clock['shown_up_at'], 'M d, h:i A') : '';
                    return ['status' => 'duplicate'] + $base + ['message' => "Ring {$entry['pigeon_ring']} was already verified$when."];
                }
                if ($clock['verification'] === 'rejected') return ['status' => 'error'] + $base + ['message' => "The result for ring {$entry['pigeon_ring']} was rejected by the club."];
                if ((int)$race['requires_show_up'] === 1 && $clock['verification'] === 'provisional' && $race['status'] === 'closed') {
                    return ['status' => 'disqualified'] + $base + ['message' => "Too late: {$race['name']} is closed and ring {$entry['pigeon_ring']} did not show up before it closed. This result is disqualified."];
                }
                $reader = in_array($a['p_reader'] ?? null, ['wireless', 'wired', 'phone'], true) ? $a['p_reader'] : null;
                q("UPDATE clock_events SET verification = 'verified', shown_up_at = ?, shown_up_by = ?, shown_up_with = ? WHERE id = ?", [now_utc(), uid(), $reader, $clock['id']]);
                $saved = row('SELECT * FROM clock_events WHERE id = ?', [$clock['id']]);
                audit('clock_events', 'update', $clock, $saved);
                return ['status' => 'verified'] + $base + ['message' => "Ring {$entry['pigeon_ring']} showed up and is verified for {$race['name']}."] + clock_receipt_details($entry, $saved);
            });
        },
    ];
}

// A new clocking (provisional), recorded in the Change history.
function new_clock(array $entry, string $receivedAt, string $source, string $timeSource = 'server'): array
{
    q('INSERT INTO clock_events (race_entry_id, pigeon_ring, received_at_utc, source, verification, server_received_at_utc, time_source) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$entry['id'], $entry['pigeon_ring'] ?? '', $receivedAt, $source, 'provisional', now_utc(), $timeSource]);
    $clock = row('SELECT * FROM clock_events WHERE id = ?', [db()->lastInsertId()]);
    after_write('clock_events', 'insert', $clock, null);   // Change history and notifications
    return $clock;
}
// Race, release, distance, flight time and speed for a receipt.
function clock_receipt_details(array $entry, array $clock): array
{
    $race = row('SELECT * FROM races WHERE id = ?', [$entry['race_id']]);
    $flight = null; $speed = null;
    if ($race['release_at_utc'] !== null) {
        $s = seconds_between($race['release_at_utc'], $clock['received_at_utc']);
        if ($s > 0) { $flight = round($s); $speed = round((float)$entry['distance_m'] * 60 / $s, 3); }
    }
    return [
        'race' => $race['name'], 'release_location' => $race['release_location'],
        'release_at_utc' => format_value($race['release_at_utc'], 'timestamp'), 'distance_m' => (float)$entry['distance_m'],
        'pigeon_ring' => $entry['pigeon_ring'] ?? $clock['pigeon_ring'], 'received_at_utc' => format_value($clock['received_at_utc'], 'timestamp'),
        'flight_seconds' => $flight, 'speed_m_per_min' => $speed,
    ];
}
function ph_time(string $utc, string $format): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
}
