<?php
// Rules applied on every save (the database triggers of the Supabase version):
// bonded birds, stickers, RFID tags and their expiry, distances, emails, and
// the Change history.
declare(strict_types=1);

// ---- Small helpers ------------------------------------------------------------------
function normalize_nfc_uid($value): ?string
{
    $uid = strtoupper(preg_replace('/[\s:-]/', '', (string)($value ?? '')));
    if ($uid === '') return null;
    if (!preg_match('/^[0-9A-F]+$/', $uid) || !in_array(strlen($uid), [8, 14, 20], true)) {
        fail('NFC tag ID must be 4, 7 or 10 bytes of hex, for example 04:A2:3B:1C:5D:80:00.');
    }
    return $uid;
}
function normalize_sticker_code($value): ?string
{
    $code = trim((string)($value ?? ''));
    return $code === '' ? null : $code;
}
// Distance in metres (haversine, the Earth's mean radius), to the millimetre:
// the same as distanceMeters() in the website.
function loft_distance_m($lat1, $lon1, $lat2, $lon2): float
{
    $r = 6371008.8;
    $a = sin(deg2rad((float)$lat2 - (float)$lat1) / 2) ** 2
       + cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) * sin(deg2rad((float)$lon2 - (float)$lon1) / 2) ** 2;
    return round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)), 3);
}
function email_ok(?string $email): bool
{
    return $email === null || (strlen($email) <= 254 && preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email));
}
// One email, one account: not on another member nor on another account.
function require_email_free(?string $email, string $table, $id): void
{
    if ($email === null) return;
    $member = val('SELECT id FROM members WHERE email = ? AND NOT (? = \'members\' AND id <=> ?) LIMIT 1', [$email, $table, $table === 'members' ? $id : null]);
    $account = val('SELECT id FROM app_profiles WHERE email = ? AND NOT (? = \'app_profiles\' AND id <=> ?) LIMIT 1', [$email, $table, $table === 'app_profiles' ? $id : null]);
    if ($member || $account) fail('This email is already used by another account. Use a different email.', 'P0001');
}
// Email kept in lower case; a new or changed email must be confirmed again
// (only the confirmation link sets email_confirmed_at).
function email_rules(string $table, array $new, ?array $old, bool $confirming = false): array
{
    if (array_key_exists('email', $new)) {
        $email = trim((string)($new['email'] ?? ''));
        $new['email'] = $email === '' ? null : strtolower($email);
        if (!email_ok($new['email'])) fail('Enter a valid email address.');
    }
    if (!$confirming) {
        if ($old === null || ($new['email'] ?? null) !== ($old['email'] ?? null)) $new['email_confirmed_at'] = null;
        else $new['email_confirmed_at'] = $old['email_confirmed_at'] ?? null;
    }
    if (($new['email'] ?? null) !== ($old['email'] ?? null)) require_email_free($new['email'] ?? null, $table, $new['id'] ?? ($old['id'] ?? null));
    return $new;
}
function trimmed($v): ?string { return $v === null ? null : trim((string)$v); }

// ---- Before a row is saved ---------------------------------------------------------
// $changed: the columns the page set (an update runs a rule only when one of its
// columns is among them, like "UPDATE OF ..." triggers).
function before_write(string $table, string $op, array $new, ?array $old, array $changed): array
{
    $touches = fn(array $columns) => $op === 'insert' || array_intersect($columns, $changed);
    $now = now_utc();
    switch ($table) {
        case 'members':
            if ($op === 'update') $new['updated_at'] = $now;
            if ($touches(['email', 'email_confirmed_at'])) $new = email_rules('members', $new, $old);
            break;

        case 'pigeons':
            $new['ring_number'] = strtoupper(trim((string)($new['ring_number'] ?? '')));
            $new['bird_name'] = trimmed($new['bird_name'] ?? null);
            $new['color'] = trimmed($new['color'] ?? null);
            $new['loft_name'] = trimmed($new['loft_name'] ?? null);
            $new['nfc_tag'] = normalize_nfc_uid($new['nfc_tag'] ?? null);
            $new['updated_at'] = $now;
            foreach (['ring_number' => [1, 80], 'bird_name' => [1, 80], 'color' => [1, 40], 'loft_name' => [1, 120]] as $c => [$min, $max]) {
                $len = mb_strlen((string)($new[$c] ?? ''));
                if ($len < $min || $len > $max) fail("new row violates check constraint \"pigeons_$c\"", '23514');
            }
            if (!val('SELECT 1 FROM members WHERE id = ? AND club_id = ?', [$new['member_id'] ?? 0, $new['club_id'] ?? 0])) fail('Choose a member of this club.');
            if ($new['nfc_tag'] !== null && !val('SELECT 1 FROM nfc_tags WHERE tag_uid = ?', [$new['nfc_tag']])) {
                fail("RFID tag {$new['nfc_tag']} is not registered. Ask the Super Admin to register it first.");
            }
            $bond = $new['bond_type'] ?? 'rfid'; $status = $new['status'] ?? 'active';
            if (($bond === 'ring' && $new['nfc_tag'] !== null) || ($bond === 'rfid' && $status === 'active' && $new['nfc_tag'] === null && ($op === 'insert' || $touches(['bond_type', 'nfc_tag', 'status'])))) {
                fail('new row violates check constraint "pigeons_bond_has_tag"', '23514');
            }
            break;

        case 'race_entries':
            $new = race_entry_rules($op, $new, $old, $touches);
            break;

        case 'races':
            $new['release_location'] = $new['release_location'] ?? null;
            break;

        case 'race_points':
            if ($op === 'update') $new['updated_at'] = $now;
            break;

        case 'entry_remark_options':
            $new['label'] = trim((string)($new['label'] ?? ''));
            if ($new['label'] === '' || mb_strlen($new['label']) > 60) fail('new row violates check constraint "entry_remark_options_label"', '23514');
            break;
    }
    return $new;
}

// Race entries: the rules of race_entries_0 … race_entries_require_registered_barcode, in order.
function race_entry_rules(string $op, array $new, ?array $old, callable $touches): array
{
    if ($op === 'update') $new['updated_at'] = now_utc();
    if (isset($new['remarks']) && is_array($new['remarks']) && count($new['remarks']) > 30) fail('new row violates check constraint "race_entries_remarks"', '23514');

    // 0. From the bonded bird.
    if ($touches(['pigeon_id'])) {
        if (empty($new['pigeon_id'])) fail('Choose a bonded bird. Bond the bird (ring, loft, name, colour and photo) on Bird bonding first.');
        $pigeon = row('SELECT * FROM pigeons WHERE id = ?', [$new['pigeon_id']]);
        if (!$pigeon) fail('That bonded bird was not found.');
        if ($pigeon['status'] !== 'active') fail("Bird {$pigeon['ring_number']} is retired and cannot be entered in a race.");
        if ($pigeon['nfc_tag'] === null && $pigeon['bond_type'] !== 'ring') fail("Bird {$pigeon['ring_number']} has no RFID tag. Add its tag on Bird bonding first.");
        $raceClub = val('SELECT club_id FROM races WHERE id = ?', [$new['race_id'] ?? 0]);
        if ((string)$raceClub !== (string)$pigeon['club_id']) fail("Bird {$pigeon['ring_number']} belongs to another club.");
        if (!empty($new['member_id']) && (int)$new['member_id'] !== (int)$pigeon['member_id']) fail("Bird {$pigeon['ring_number']} is bonded to another member.");
        $new['member_id'] = (int)$pigeon['member_id'];
        $new['pigeon_ring'] = $pigeon['ring_number'];
        if ($pigeon['bond_type'] === 'ring') {
            if (trim((string)($new['barcode'] ?? '')) === '') fail("Bird {$pigeon['ring_number']} is bonded by ring number only: scan or type its sticker.");
            $new['nfc_tag'] = null; $new['nfc_read_with'] = null;
        } elseif ($pigeon['nfc_tag'] !== null) {
            $new['nfc_tag'] = $pigeon['nfc_tag'];
            $new['nfc_read_with'] = $new['nfc_read_with'] ?? $pigeon['nfc_read_with'];
        }
    }
    // 1. Sticker rule of the club (new entries only).
    if ($op === 'insert' && trim((string)($new['barcode'] ?? '')) === '') {
        $rule = val('SELECT c.rfid_entry_sticker FROM races r JOIN clubs c ON c.id = r.club_id WHERE r.id = ?', [$new['race_id'] ?? 0]) ?? 'required';
        if ($rule === 'required') fail('This club requires the sticker for every entry. Scan or type the sticker reference number (Club settings can make it optional for RFID entries).', '23514');
        if (empty($new['nfc_tag'])) fail('An entry without a sticker needs the bird\'s RFID tag.', '23514');
    }
    // a, b. RFID tag: normalized and registered.
    if ($touches(['nfc_tag'])) {
        $new['nfc_tag'] = normalize_nfc_uid($new['nfc_tag'] ?? null);
        if ($new['nfc_tag'] !== null && !val('SELECT 1 FROM nfc_tags WHERE tag_uid = ?', [$new['nfc_tag']])) {
            fail('This NFC tag is not registered. Ask the Super Admin to register it first.', '23514');
        }
    }
    // c. RFID tag once per race, and not while still active in another race.
    if ($touches(['nfc_tag', 'race_id']) && !empty($new['nfc_tag'])) {
        $id = $new['id'] ?? null;
        if (val('SELECT 1 FROM race_entries WHERE race_id = ? AND nfc_tag = ? AND NOT (id <=> ?)', [$new['race_id'], $new['nfc_tag'], $id])) {
            fail('This RFID tag is already entered in this race. Each RFID tag can be used once per race.', '23514');
        }
        $other = row("SELECT e.nfc_expires_at, r.name AS race_name, r.club_id FROM race_entries e JOIN races r ON r.id = e.race_id
                       WHERE e.nfc_tag = ? AND e.race_id <> ? AND NOT (e.id <=> ?) AND r.status <> 'closed'
                         AND (e.nfc_expires_at IS NULL OR e.nfc_expires_at > UTC_TIMESTAMP(6))
                       ORDER BY e.nfc_expires_at IS NOT NULL, e.nfc_expires_at LIMIT 1", [$new['nfc_tag'], $new['race_id'], $id]);
        if ($other) {
            $club = val('SELECT club_id FROM races WHERE id = ?', [$new['race_id']]);
            $where = (string)$other['club_id'] === (string)$club ? 'race "' . $other['race_name'] . '"' : 'another race';
            $until = $other['nfc_expires_at'] !== null
                ? ' until ' . (new DateTimeImmutable($other['nfc_expires_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y h:i A')
                : ' until that race is closed';
            fail("This RFID tag is still active in $where$until. It can be entered in a new race once it expires there or that race is closed.", '23514');
        }
    }
    // d. Tag expiry from the race.
    if ($touches(['race_id', 'nfc_tag', 'nfc_expires_at'])) {
        $new['nfc_expires_at'] = race_entry_tag_expiry($new['race_id'] ?? 0, $new['nfc_tag'] ?? null, $new['created_at'] ?? null);
    }
    // e. The loft the bird flies to, kept with the entry.
    if ($touches(['member_id']) && (($new['loft_latitude'] ?? null) === null || ($new['loft_longitude'] ?? null) === null)) {
        $m = row('SELECT latitude, longitude FROM members WHERE id = ?', [$new['member_id'] ?? 0]);
        if ($m) { $new['loft_latitude'] = $m['latitude']; $new['loft_longitude'] = $m['longitude']; }
    }
    // Sticker registered this season, and not clashing with this member's other stickers.
    if ($touches(['barcode', 'race_id', 'member_id'])) {
        if (trim((string)($new['barcode'] ?? '')) === '') {
            if (empty($new['nfc_tag'])) fail('Choose a barcode registered by the Super Admin, or tap the pigeon\'s NFC tag.', '23514');
            $new['barcode'] = null;
        } else {
            $new['barcode'] = normalize_sticker_code($new['barcode']);
            $sticker = row('SELECT * FROM barcode_stickers WHERE barcode_number = ? AND is_registered = 1', [$new['barcode']]);
            if (!$sticker) fail("Sticker {$new['barcode']} is not registered this season. Ask the Super Admin to register it first.", '23514');
            $clash = val('SELECT 1 FROM race_entries e JOIN barcode_stickers o ON o.barcode_number = e.barcode
                           WHERE e.race_id = ? AND e.member_id = ? AND NOT (e.id <=> ?)
                             AND ((? IS NOT NULL AND (o.scratch_code = ? OR o.barcode_number = ?)) OR o.scratch_code = ?) LIMIT 1',
                [$new['race_id'], $new['member_id'], $new['id'] ?? null, $sticker['scratch_code'], $sticker['scratch_code'], $sticker['scratch_code'], $new['barcode']]);
            if ($clash) fail("Sticker {$new['barcode']} clashes with another sticker this member already has in this race. Use a different sticker for this pigeon.", '23514');
        }
    }
    if (isset($new['distance_m']) && (float)$new['distance_m'] < 0) fail('new row violates check constraint "race_entries_distance"', '23514');
    return $new;
}
function race_entry_tag_expiry($raceId, ?string $tag, ?string $createdAt): ?string
{
    if ($tag === null) return null;
    $race = row('SELECT nfc_expiry_days, release_at_utc FROM races WHERE id = ?', [$raceId]);
    if (!$race || $race['nfc_expiry_days'] === null) return null;
    $from = $race['release_at_utc'] ?? $createdAt ?? now_utc();
    return (new DateTimeImmutable((string)$from, new DateTimeZone('UTC')))->modify('+' . (int)$race['nfc_expiry_days'] . ' days')->format('Y-m-d H:i:s.u');
}

// ---- Before a row is deleted --------------------------------------------------------
function before_delete(string $table, array $old): void
{
    if ($table === 'members') {
        // The member's login goes with the member.
        q("DELETE u FROM auth_users u JOIN app_profiles p ON p.id = u.id WHERE p.member_id = ? AND p.role = 'member'", [$old['id']]);
    }
}

// ---- After a row is saved or deleted ----------------------------------------------------
function after_write(string $table, string $op, ?array $new, ?array $old): void
{
    if ($table === 'races' && $op === 'update') {
        if (!same_coords($new, $old, 'release_latitude', 'release_longitude')) recalculate_race_distances((int)$new['id']);
        if ((string)$new['nfc_expiry_days'] !== (string)$old['nfc_expiry_days'] || (string)$new['release_at_utc'] !== (string)$old['release_at_utc']) {
            update_race_tag_expiry((int)$new['id']);
        }
    }
    if ($table === 'race_points' && $op === 'update'
        && ($new['location'] !== $old['location'] || !same_coords($new, $old, 'latitude', 'longitude'))) {
        race_point_moved($new, $old);
    }
    if (in_array($table, ['races', 'race_entries', 'clock_events', 'pigeons'], true)) audit($table, $op, $old, $new);
    // Billing: each bird encoded is counted; deleting keeps the count (non-refundable).
    if ($table === 'race_entries' && $op === 'insert') billing_count_entry($new);
    if ($table === 'race_entries' && $op === 'delete') billing_mark_deleted($old);
    // Push notifications: a pigeon clocked; a race opened.
    if ($table === 'clock_events' && $op === 'insert') push_on_clock($new);
    if ($table === 'races' && $new !== null && $new['status'] === 'open' && ($old === null || $old['status'] !== 'open')) push_on_race_open($new);
}
function same_coords(array $a, array $b, string $lat, string $lon): bool
{
    return abs((float)$a[$lat] - (float)$b[$lat]) < 1e-9 && abs((float)$a[$lon] - (float)$b[$lon]) < 1e-9;
}

// A race's release point moved: every entry's distance follows (entries whose
// loft is not known keep their distance).
function recalculate_race_distances(int $raceId): int
{
    $race = row('SELECT release_latitude, release_longitude FROM races WHERE id = ?', [$raceId]);
    if (!$race) return 0;
    $changed = 0;
    foreach (rows('SELECT * FROM race_entries WHERE race_id = ? AND loft_latitude IS NOT NULL AND loft_longitude IS NOT NULL FOR UPDATE', [$raceId]) as $e) {
        $d = loft_distance_m($race['release_latitude'], $race['release_longitude'], $e['loft_latitude'], $e['loft_longitude']);
        if (abs((float)$e['distance_m'] - $d) < 0.0005) continue;
        q('UPDATE race_entries SET distance_m = ? WHERE id = ?', [$d, $e['id']]);
        audit('race_entries', 'update', $e, row('SELECT * FROM race_entries WHERE id = ?', [$e['id']]));
        $changed++;
    }
    return $changed;
}
function update_race_tag_expiry(int $raceId): void
{
    foreach (rows('SELECT * FROM race_entries WHERE race_id = ? AND nfc_tag IS NOT NULL FOR UPDATE', [$raceId]) as $e) {
        $expiry = race_entry_tag_expiry($raceId, $e['nfc_tag'], $e['created_at']);
        if ((string)$expiry === (string)$e['nfc_expires_at']) continue;
        q('UPDATE race_entries SET nfc_expires_at = ? WHERE id = ?', [$expiry, $e['id']]);
        audit('race_entries', 'update', $e, row('SELECT * FROM race_entries WHERE id = ?', [$e['id']]));
    }
}
// A race point's name or coordinates changed: the races using it follow. Races
// made before races were linked to a race point are found by the point's old
// name AND old coordinates in the same club, unless another point of the club
// has that same name and coordinates.
function race_point_moved(array $new, array $old): void
{
    $races = rows('SELECT * FROM races r
                    WHERE (r.race_point_id = ?
                           OR (r.race_point_id IS NULL AND r.release_location = ? AND r.club_id = ?
                               AND r.release_latitude = ? AND r.release_longitude = ?
                               AND NOT EXISTS (SELECT 1 FROM race_points p WHERE p.id <> ? AND p.club_id = ? AND p.location = ?
                                                AND p.latitude = ? AND p.longitude = ?)))
                      AND (NOT (r.release_location <=> ?) OR NOT (r.release_latitude <=> ?) OR NOT (r.release_longitude <=> ?)) FOR UPDATE',
        [$new['id'], $old['location'], $new['club_id'], $old['latitude'], $old['longitude'],
         $new['id'], $new['club_id'], $old['location'], $old['latitude'], $old['longitude'],
         $new['location'], $new['latitude'], $new['longitude']]);
    foreach ($races as $race) {
        q('UPDATE races SET race_point_id = COALESCE(race_point_id, ?), release_location = ?, release_latitude = ?, release_longitude = ? WHERE id = ?',
            [$new['id'], $new['location'], $new['latitude'], $new['longitude'], $race['id']]);
        $saved = row('SELECT * FROM races WHERE id = ?', [$race['id']]);
        after_write('races', 'update', $saved, $race);
    }
}

// ---- Change history ---------------------------------------------------------------------
function audit(string $table, string $op, ?array $old, ?array $new): void
{
    $rowData = $op === 'delete' ? $old : $new;
    if ($op === 'update' && json_encode($old) === json_encode($new)) return;
    $club = $rowData['club_id'] ?? null;
    if ($club === null && isset($rowData['race_id'])) $club = val('SELECT club_id FROM races WHERE id = ?', [$rowData['race_id']]);
    if ($club === null && isset($rowData['race_entry_id'])) {
        $club = val('SELECT r.club_id FROM race_entries e JOIN races r ON r.id = e.race_id WHERE e.id = ?', [$rowData['race_entry_id']]);
    }
    $p = profile();
    $name = $p['username'] ?? ($p ? 'Unknown account' : 'RFID reader / system');
    q('INSERT INTO audit_log (club_id, table_name, row_id, action, changed_by, changed_by_name, old_data, new_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $club, $table, $rowData['id'] ?? null, $op, $p['id'] ?? null, $name,
        $op === 'insert' ? null : json_encode(format_row($table, $old), JSON_UNESCAPED_UNICODE),
        $op === 'delete' ? null : json_encode(format_row($table, $new), JSON_UNESCAPED_UNICODE),
    ]);
}
