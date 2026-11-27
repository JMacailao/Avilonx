<?php
// Billing (same rules as billing.sql in the Supabase version): clubs pay per
// bird encoded in a race entry (not practice), non-refundable; bills for a
// start date to end date (Philippine days, no overlap); settling numbers the
// invoice; warnings; closing a club locks out its admin and staff.
declare(strict_types=1);

const BILLING_TABLES = [
    "CREATE TABLE IF NOT EXISTS club_bills (
      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      club_id BIGINT NOT NULL,
      start_date DATE NOT NULL,
      end_date DATE NOT NULL,
      birds INT NOT NULL DEFAULT 0,
      amount DECIMAL(12,2) NOT NULL DEFAULT 0,
      created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
      created_by CHAR(36) NULL,
      invoice_no VARCHAR(20) NULL,
      settled_at DATETIME(6) NULL,
      paid_on DATE NULL,
      pay_method VARCHAR(40) NULL,
      pay_reference VARCHAR(80) NULL,
      pay_note VARCHAR(300) NULL,
      UNIQUE KEY club_bills_invoice (invoice_no),
      KEY club_bills_club (club_id, start_date),
      CONSTRAINT club_bills_dates CHECK (end_date >= start_date),
      CONSTRAINT club_bills_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS billing_counts (
      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      club_id BIGINT NOT NULL,
      race_entry_id BIGINT NOT NULL,
      race_id BIGINT NULL,
      race_name VARCHAR(180) NULL,
      member_no VARCHAR(40) NULL,
      member_name VARCHAR(180) NULL,
      pigeon_ring VARCHAR(80) NULL,
      price DECIMAL(10,2) NOT NULL,
      encoded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
      encoded_by CHAR(36) NULL,
      deleted_at DATETIME(6) NULL,
      bill_id BIGINT NULL,
      UNIQUE KEY billing_counts_entry (race_entry_id),
      KEY billing_counts_club_time (club_id, encoded_at),
      KEY billing_counts_bill (bill_id),
      CONSTRAINT billing_counts_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS club_warnings (
      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      club_id BIGINT NOT NULL,
      bill_id BIGINT NULL,
      message VARCHAR(1000) NOT NULL,
      created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
      created_by CHAR(36) NULL,
      seen_at DATETIME(6) NULL,
      seen_by CHAR(36) NULL,
      KEY club_warnings_club (club_id),
      CONSTRAINT club_warnings_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS billing_log (
      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      club_id BIGINT NOT NULL,
      action VARCHAR(30) NOT NULL,
      detail VARCHAR(300) NULL,
      created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
      created_by CHAR(36) NULL,
      KEY billing_log_club (club_id, created_at),
      CONSTRAINT billing_log_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS billing_invoice_counter (
      id TINYINT NOT NULL PRIMARY KEY,
      n INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB",
];
const BILLING_CLUB_COLUMNS = [
    'bird_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 5.00',
    'is_closed' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'closed_reason' => 'VARCHAR(500) NULL',
    'closed_at' => 'DATETIME(6) NULL',
];
const BILL_DAY = 'DATE(DATE_ADD(%s, INTERVAL 8 HOUR))';   // Philippine calendar day of a UTC time

// Databases imported before billing get its tables and columns here (MySQL and MariaDB).
function ensure_billing_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (val("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_invoice_counter'")
        && val("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clubs' AND COLUMN_NAME = 'closed_at'")) return;
    $have = array_column(rows("SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clubs'"), 'c');
    foreach (BILLING_CLUB_COLUMNS as $col => $type) if (!in_array($col, $have, true)) db()->exec("ALTER TABLE clubs ADD COLUMN `$col` $type");
    foreach (BILLING_TABLES as $sql) db()->exec($sql);
    db()->exec('INSERT IGNORE INTO billing_invoice_counter (id, n) VALUES (1, 0)');
}

// Is the signed-in club admin's / staff's club closed? (role() gives them no access then.)
function club_is_closed(?int $club): bool
{
    if (!$club) return false;
    ensure_billing_schema();
    return (bool)val('SELECT is_closed FROM clubs WHERE id = ?', [$club]);
}

// ---- Counting (called after a race entry is saved or deleted) ------------------------------
function billing_count_entry(array $entry): void
{
    ensure_billing_schema();
    $race = row('SELECT id, name, club_id, is_practice FROM races WHERE id = ?', [$entry['race_id']]);
    if (!$race || (int)$race['is_practice'] === 1 || !$race['club_id']) return;
    $member = row('SELECT member_no, full_name FROM members WHERE id = ?', [$entry['member_id']]);
    q('INSERT IGNORE INTO billing_counts (club_id, race_entry_id, race_id, race_name, member_no, member_name, pigeon_ring, price, encoded_by)
       SELECT c.id, ?, ?, ?, ?, ?, ?, c.bird_price, ? FROM clubs c WHERE c.id = ?',
        [$entry['id'], $race['id'], $race['name'], $member['member_no'] ?? null, $member['full_name'] ?? null, $entry['pigeon_ring'] ?? null, uid(), $race['club_id']]);
}
function billing_mark_deleted(array $entry): void
{
    ensure_billing_schema();
    q('UPDATE billing_counts SET deleted_at = UTC_TIMESTAMP(6) WHERE race_entry_id = ? AND deleted_at IS NULL', [$entry['id']]);
}

// ---- Helpers -------------------------------------------------------------------------------------
function bill_day(string $col): string { return sprintf(BILL_DAY, $col); }
function ph_today(): string { return (string)val('SELECT ' . bill_day('UTC_TIMESTAMP()')); }
function billing_date($v, string $label): string
{
    $v = (string)$v;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) fail("Choose the $label.");
    return $v;
}
// The club the caller may see: any club for the Super Admin, their own for a club admin.
function billing_club($club): int
{
    ensure_billing_schema();
    $p = profile();
    if ($p && $p['role'] === 'superadmin' && $club) return (int)$club;
    if ($p && $p['role'] === 'admin' && $p['club_id'] && (!$club || (int)$club === (int)$p['club_id'])) return (int)$p['club_id'];
    deny('Billing is for the Super Admin and the club administrator.');
}
function billing_superadmin(): void { ensure_billing_schema(); if (!is_superadmin()) deny('Super Admin only.'); }
function billing_log(int $club, string $action, ?string $detail): void
{
    q('INSERT INTO billing_log (club_id, action, detail, created_by) VALUES (?, ?, ?, ?)', [$club, $action, $detail === null ? null : mb_substr($detail, 0, 300), uid()]);
}
function bill_out(?array $b): ?array
{
    if (!$b) return null;
    return [
        'id' => (int)$b['id'], 'club_id' => (int)$b['club_id'], 'start_date' => $b['start_date'], 'end_date' => $b['end_date'],
        'birds' => (int)$b['birds'], 'amount' => (float)$b['amount'], 'created_at' => format_value($b['created_at'], 'timestamptz'),
        'created_by' => $b['created_by'], 'invoice_no' => $b['invoice_no'], 'settled_at' => format_value($b['settled_at'], 'timestamptz'),
        'paid_on' => $b['paid_on'], 'pay_method' => $b['pay_method'], 'pay_reference' => $b['pay_reference'], 'pay_note' => $b['pay_note'],
    ];
}
function warning_out(array $w): array
{
    return ['id' => (int)$w['id'], 'club_id' => (int)$w['club_id'], 'bill_id' => $w['bill_id'] === null ? null : (int)$w['bill_id'], 'message' => $w['message'],
        'created_at' => format_value($w['created_at'], 'timestamptz'), 'seen_at' => format_value($w['seen_at'], 'timestamptz')];
}
function money_text($v): string { return number_format((float)$v, 2, '.', ''); }

function billing_functions(): array
{
    return [
        'superadmin_billing_summary' => function ($a) {
            billing_superadmin();
            $s = billing_date($a['p_start'] ?? '', 'start date'); $e = billing_date($a['p_end'] ?? '', 'end date');
            $day = bill_day('b.encoded_at');
            return array_map(fn($r) => [
                'club_id' => (int)$r['id'], 'club_name' => $r['name'], 'bird_price' => (float)$r['bird_price'],
                'birds' => (int)$r['birds'], 'deleted' => (int)$r['deleted'], 'amount' => (float)$r['amount'],
                'unbilled' => (int)$r['unbilled'], 'unbilled_amount' => (float)$r['unbilled_amount'],
                'unpaid_bills' => (int)$r['unpaid_bills'], 'unpaid_amount' => (float)$r['unpaid_amount'],
                'is_closed' => (bool)(int)$r['is_closed'], 'closed_reason' => $r['closed_reason'], 'open_warnings' => (int)$r['open_warnings'],
            ], rows("SELECT c.id, c.name, c.bird_price, c.is_closed, c.closed_reason,
                (SELECT COUNT(*) FROM billing_counts b WHERE b.club_id = c.id AND $day BETWEEN ? AND ?) AS birds,
                (SELECT COUNT(*) FROM billing_counts b WHERE b.club_id = c.id AND b.deleted_at IS NOT NULL AND $day BETWEEN ? AND ?) AS deleted,
                (SELECT COALESCE(SUM(b.price), 0) FROM billing_counts b WHERE b.club_id = c.id AND $day BETWEEN ? AND ?) AS amount,
                (SELECT COUNT(*) FROM billing_counts b WHERE b.club_id = c.id AND b.bill_id IS NULL) AS unbilled,
                (SELECT COALESCE(SUM(b.price), 0) FROM billing_counts b WHERE b.club_id = c.id AND b.bill_id IS NULL) AS unbilled_amount,
                (SELECT COUNT(*) FROM club_bills x WHERE x.club_id = c.id AND x.settled_at IS NULL) AS unpaid_bills,
                (SELECT COALESCE(SUM(x.amount), 0) FROM club_bills x WHERE x.club_id = c.id AND x.settled_at IS NULL) AS unpaid_amount,
                (SELECT COUNT(*) FROM club_warnings w WHERE w.club_id = c.id AND w.seen_at IS NULL) AS open_warnings
              FROM clubs c ORDER BY c.name", [$s, $e, $s, $e, $s, $e]));
        },
        'billing_entries' => function ($a) {
            $club = billing_club($a['p_club_id'] ?? null);
            $s = billing_date($a['p_start'] ?? '', 'start date'); $e = billing_date($a['p_end'] ?? '', 'end date');
            return array_map(fn($r) => [
                'id' => (int)$r['id'], 'encoded_at' => format_value($r['encoded_at'], 'timestamptz'), 'race_name' => $r['race_name'],
                'member_no' => $r['member_no'], 'member_name' => $r['member_name'], 'pigeon_ring' => $r['pigeon_ring'],
                'price' => (float)$r['price'], 'deleted' => $r['deleted_at'] !== null, 'bill_id' => $r['bill_id'] === null ? null : (int)$r['bill_id'],
            ], rows('SELECT * FROM billing_counts b WHERE b.club_id = ? AND ' . bill_day('b.encoded_at') . ' BETWEEN ? AND ? ORDER BY b.encoded_at, b.id LIMIT 5000', [$club, $s, $e]));
        },
        'billing_bills' => function ($a) {
            $club = billing_club($a['p_club_id'] ?? null);
            return array_map('bill_out', rows('SELECT * FROM club_bills WHERE club_id = ? ORDER BY start_date DESC', [$club]));
        },
        'club_billing_status' => function ($a) {
            $club = billing_club(null);
            $c = row('SELECT id, name, bird_price FROM clubs WHERE id = ?', [$club]);
            $unpaid = row('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM club_bills WHERE club_id = ? AND settled_at IS NULL', [$club]);
            $unbilled = row('SELECT COUNT(*) AS n, COALESCE(SUM(price), 0) AS amount FROM billing_counts WHERE club_id = ? AND bill_id IS NULL', [$club]);
            return ['club_id' => (int)$c['id'], 'club_name' => $c['name'], 'bird_price' => (float)$c['bird_price'],
                'unpaid_bills' => (int)$unpaid['n'], 'unpaid_amount' => (float)$unpaid['amount'],
                'unbilled' => (int)$unbilled['n'], 'unbilled_amount' => (float)$unbilled['amount'],
                'last_bill_end' => val('SELECT MAX(end_date) FROM club_bills WHERE club_id = ?', [$club])];
        },
        'club_account_status' => function ($a) {
            $p = profile();
            if (!$p || !in_array($p['role'], ['admin', 'staff'], true) || !$p['club_id']) return ['is_closed' => false, 'warnings' => []];
            ensure_billing_schema();
            $c = row('SELECT name, is_closed, closed_reason FROM clubs WHERE id = ?', [$p['club_id']]);
            $closed = (bool)(int)$c['is_closed'];
            $warnings = $p['role'] === 'admin' && !$closed ? array_map(fn($w) => ['id' => (int)$w['id'], 'message' => $w['message'], 'created_at' => format_value($w['created_at'], 'timestamptz')],
                rows('SELECT * FROM club_warnings WHERE club_id = ? AND seen_at IS NULL ORDER BY created_at', [$p['club_id']])) : [];
            return ['is_closed' => $closed, 'closed_reason' => $c['closed_reason'], 'club_name' => $c['name'], 'warnings' => $warnings];
        },
        'club_dismiss_warning' => function ($a) {
            $club = billing_club(null);
            q('UPDATE club_warnings SET seen_at = UTC_TIMESTAMP(6), seen_by = ? WHERE id = ? AND club_id = ? AND seen_at IS NULL', [uid(), (int)($a['p_id'] ?? 0), $club]);
            return null;
        },
        'superadmin_set_bird_price' => function ($a) {
            billing_superadmin();
            $price = $a['p_price'] ?? null;
            if (!is_numeric($price) || (float)$price < 0 || (float)$price > 10000) fail('Enter a price from 0 to 10,000.');
            $club = (int)($a['p_club_id'] ?? 0);
            q('UPDATE clubs SET bird_price = ? WHERE id = ?', [round((float)$price, 2), $club]);
            billing_log($club, 'price', 'Price per bird: ' . money_text($price));
            return null;
        },
        'superadmin_create_bill' => function ($a) {
            billing_superadmin();
            $club = (int)($a['p_club_id'] ?? 0);
            $s = (string)($a['p_start'] ?? ''); $e = (string)($a['p_end'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e) || $e < $s) fail('Choose a start date and an end date (the end on or after the start).');
            if ($e > ph_today()) fail('The end date cannot be after today.');
            return transaction(function () use ($club, $s, $e) {
                if (!val('SELECT 1 FROM clubs WHERE id = ? FOR UPDATE', [$club])) fail('Club not found.');
                $overlap = row('SELECT * FROM club_bills WHERE club_id = ? AND start_date <= ? AND end_date >= ? LIMIT 1', [$club, $e, $s]);
                if ($overlap) {
                    $d = fn($x) => date('M d, Y', strtotime($x));
                    fail("These dates overlap the bill for {$d($overlap['start_date'])} to {$d($overlap['end_date'])}. Start after {$d($overlap['end_date'])}.");
                }
                q('INSERT INTO club_bills (club_id, start_date, end_date, created_by) VALUES (?, ?, ?, ?)', [$club, $s, $e, uid()]);
                $id = (int)db()->lastInsertId();
                q('UPDATE billing_counts SET bill_id = ? WHERE club_id = ? AND bill_id IS NULL AND ' . bill_day('encoded_at') . ' BETWEEN ? AND ?', [$id, $club, $s, $e]);
                q('UPDATE club_bills SET birds = (SELECT COUNT(*) FROM billing_counts WHERE bill_id = ?), amount = (SELECT COALESCE(SUM(price), 0) FROM billing_counts WHERE bill_id = ?) WHERE id = ?', [$id, $id, $id]);
                $bill = row('SELECT * FROM club_bills WHERE id = ?', [$id]);
                billing_log($club, 'bill', "Bill $s to $e: {$bill['birds']} birds, " . money_text($bill['amount']));
                return bill_out($bill);
            });
        },
        'superadmin_delete_bill' => function ($a) {
            billing_superadmin();
            return transaction(function () use ($a) {
                $bill = row('SELECT * FROM club_bills WHERE id = ? FOR UPDATE', [(int)($a['p_bill_id'] ?? 0)]);
                if (!$bill) fail('Bill not found.');
                if ($bill['settled_at'] !== null) fail('A settled bill cannot be removed.');
                q('UPDATE billing_counts SET bill_id = NULL WHERE bill_id = ?', [$bill['id']]);
                q('UPDATE club_warnings SET bill_id = NULL WHERE bill_id = ?', [$bill['id']]);
                q('DELETE FROM club_bills WHERE id = ?', [$bill['id']]);
                billing_log((int)$bill['club_id'], 'bill removed', "Bill {$bill['start_date']} to {$bill['end_date']}");
                return null;
            });
        },
        'superadmin_settle_bill' => function ($a) {
            billing_superadmin();
            $paid = (string)($a['p_paid_on'] ?? ''); $method = trim((string)($a['p_method'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid) || $method === '') fail('Enter the payment date and how it was paid.');
            return transaction(function () use ($a, $paid, $method) {
                $bill = row('SELECT * FROM club_bills WHERE id = ? FOR UPDATE', [(int)($a['p_bill_id'] ?? 0)]);
                if (!$bill) fail('Bill not found.');
                if ($bill['settled_at'] !== null) fail('This bill is already settled.');
                q('UPDATE billing_invoice_counter SET n = LAST_INSERT_ID(n + 1) WHERE id = 1');
                $n = (int)val('SELECT LAST_INSERT_ID()');
                $invoice = 'AVX-' . substr($paid, 0, 4) . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
                $ref = trim((string)($a['p_reference'] ?? '')); $note = trim((string)($a['p_note'] ?? ''));
                q('UPDATE club_bills SET settled_at = UTC_TIMESTAMP(6), paid_on = ?, pay_method = ?, pay_reference = ?, pay_note = ?, invoice_no = ? WHERE id = ?',
                    [$paid, mb_substr($method, 0, 40), $ref === '' ? null : mb_substr($ref, 0, 80), $note === '' ? null : mb_substr($note, 0, 300), $invoice, $bill['id']]);
                $bill = row('SELECT * FROM club_bills WHERE id = ?', [$bill['id']]);
                billing_log((int)$bill['club_id'], 'settled', "$invoice: " . money_text($bill['amount']) . ", $method " . ($ref ?: ''));
                return bill_out($bill);
            });
        },
        'superadmin_send_warning' => function ($a) {
            billing_superadmin();
            $msg = trim((string)($a['p_message'] ?? ''));
            if (mb_strlen($msg) < 3) fail('Write the warning message.');
            $club = (int)($a['p_club_id'] ?? 0);
            q('INSERT INTO club_warnings (club_id, bill_id, message, created_by) VALUES (?, ?, ?, ?)', [$club, isset($a['p_bill_id']) ? (int)$a['p_bill_id'] : null, mb_substr($msg, 0, 1000), uid()]);
            billing_log($club, 'warning', $msg);
            return null;
        },
        'superadmin_billing_warnings' => function ($a) {
            billing_superadmin();
            return array_map('warning_out', rows('SELECT * FROM club_warnings WHERE club_id = ? ORDER BY created_at DESC LIMIT 50', [(int)($a['p_club_id'] ?? 0)]));
        },
        'superadmin_set_club_closed' => function ($a) {
            billing_superadmin();
            $club = (int)($a['p_club_id'] ?? 0); $closed = !empty($a['p_closed']); $reason = trim((string)($a['p_reason'] ?? ''));
            if ($closed && mb_strlen($reason) < 3) fail('Write the reason (the club admin sees it).');
            q('UPDATE clubs SET is_closed = ?, closed_reason = ?, closed_at = ? WHERE id = ?', [$closed ? 1 : 0, $closed ? mb_substr($reason, 0, 500) : null, $closed ? gmdate('Y-m-d H:i:s') : null, $club]);
            billing_log($club, $closed ? 'closed' : 'reopened', $reason === '' ? null : $reason);
            return null;
        },
        'superadmin_billing_log' => function ($a) {
            billing_superadmin();
            return array_map(fn($l) => ['id' => (int)$l['id'], 'club_id' => (int)$l['club_id'], 'action' => $l['action'], 'detail' => $l['detail'], 'created_at' => format_value($l['created_at'], 'timestamptz')],
                rows('SELECT * FROM billing_log WHERE club_id = ? ORDER BY created_at DESC LIMIT 100', [(int)($a['p_club_id'] ?? 0)]));
        },
    ];
}
