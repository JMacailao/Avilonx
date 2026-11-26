<?php
// Table reads and writes from the pages (supabase.from(...)), with the same
// access rules as the Supabase version (row-level security), per role and club.
declare(strict_types=1);

// Which rows the signed-in account may read / change, as SQL on alias "t".
// null = no access at all. Returns [sql, params].
function read_rule(string $table): ?array
{
    $p = profile();
    if (!$p) return null;
    $uid = $p['id']; $club = current_club_id(); $member = current_member_id(); $manage = can_manage_club_data();
    $m = $manage ? 1 : 0;
    switch ($table) {
        case 'app_profiles':
            return ['(t.id = ? OR ? OR (? AND t.club_id = ?))', [$uid, is_superadmin() ? 1 : 0, is_app_admin() ? 1 : 0, $club]];
        case 'audit_log':
        case 'entry_remark_options':
            return ['(? AND t.club_id = ?)', [$m, $club]];
        case 'barcode_stickers':
        case 'nfc_tags':
            return ['1', []];
        case 'clubs':
            return ['(? OR t.id = ?)', [is_superadmin() ? 1 : 0, $club]];
        case 'clock_events':
            return ['EXISTS (SELECT 1 FROM race_entries e JOIN races r ON r.id = e.race_id WHERE e.id = t.race_entry_id AND (e.member_id = ? OR (? AND r.club_id = ?)))', [$member, $m, $club]];
        case 'members':
            return ['((? AND t.club_id = ?) OR t.id = ?)', [$m, $club, $member]];
        case 'clock_videos':
        case 'pigeons':
            return ['((? AND t.club_id = ?) OR t.member_id = ?)', [$m, $club, $member]];
        case 'race_entries':
            return ['(t.member_id = ? OR (? AND EXISTS (SELECT 1 FROM races r WHERE r.id = t.race_id AND r.club_id = ?)))', [$member, $m, $club]];
        case 'race_points':
        case 'races':
            return ['t.club_id = ?', [$club]];
    }
    return null;
}
// Rows that may be changed or deleted (and that a new or changed row must satisfy).
function write_rule(string $table): ?array
{
    if (!profile()) return null;
    $club = current_club_id(); $m = can_manage_club_data() ? 1 : 0; $a = is_app_admin() ? 1 : 0;
    switch ($table) {
        case 'clock_events':
            return ['(? AND EXISTS (SELECT 1 FROM race_entries e JOIN races r ON r.id = e.race_id WHERE e.id = t.race_entry_id AND r.club_id = ?))', [$m, $club]];
        case 'entry_remark_options':
            return ['(? AND t.club_id = ?)', [$a, $club]];
        case 'members':
        case 'pigeons':
        case 'race_points':
        case 'races':
            return ['(? AND t.club_id = ?)', [$m, $club]];
        case 'race_entries':
            return ['(? AND EXISTS (SELECT 1 FROM races r WHERE r.id = t.race_id AND r.club_id = ?) AND EXISTS (SELECT 1 FROM members mm WHERE mm.id = t.member_id AND mm.club_id = ?))', [$m, $club, $club]];
    }
    return null;
}
// Filled in on new rows when the page leaves them out.
function insert_defaults(string $table, array $row): array
{
    if (in_array($table, ['members', 'race_points', 'races', 'pigeons', 'entry_remark_options'], true) && !array_key_exists('club_id', $row)) {
        $row['club_id'] = current_club_id();
    }
    if ($table === 'pigeons' && !array_key_exists('created_by', $row)) $row['created_by'] = uid();
    return $row;
}
function primary_key(string $table): string
{
    return ['nfc_tags' => 'tag_uid', 'barcode_stickers' => 'barcode_number', 'account_devices' => 'user_id'][$table] ?? 'id';
}

// ---- The query endpoint ------------------------------------------------------------
function run_query(array $q): array
{
    $table = (string)($q['table'] ?? '');
    if (!preg_match('/^[a-z_]+$/', $table)) fail('Bad table name.', '42P01');
    if (!profile()) fail('Sign in first.', '42501', 401);
    $op = $q['op'] ?? 'select';
    if ($op === 'select') return select_rows($table, $q);
    if (in_array($op, ['insert', 'update', 'delete'], true)) {
        return transaction(fn() => write_rows($table, $op, $q));
    }
    fail("Operation $op is not available.", '0A000');
}

function parse_columns(string $table, $columns): ?array
{
    $text = trim((string)($columns ?? '*'));
    if ($text === '' || $text === '*') return null;
    $list = array_map('trim', explode(',', $text));
    foreach ($list as $c) column_kind($table, $c);
    return $list;
}
function where_from_filters(string $table, array $filters, array &$params): string
{
    $sql = [];
    foreach ($filters as $f) {
        [$column, $op, $value] = array_pad($f, 3, null);
        $kind = column_kind($table, (string)$column);
        $col = 't.' . quote_ident((string)$column);
        switch ($op) {
            case 'eq':
                if ($value === null) { $sql[] = "$col IS NULL"; break; }
                $sql[] = "$col = ?"; $params[] = db_value($value, $kind); break;
            case 'neq': $sql[] = "$col <> ?"; $params[] = db_value($value, $kind); break;
            case 'gt': $sql[] = "$col > ?"; $params[] = db_value($value, $kind); break;
            case 'gte': $sql[] = "$col >= ?"; $params[] = db_value($value, $kind); break;
            case 'lt': $sql[] = "$col < ?"; $params[] = db_value($value, $kind); break;
            case 'lte': $sql[] = "$col <= ?"; $params[] = db_value($value, $kind); break;
            case 'is':
                if ($value === null || $value === 'null') $sql[] = "$col IS NULL";
                elseif ($value === true || $value === 'true') $sql[] = "$col = 1";
                elseif ($value === false || $value === 'false') $sql[] = "$col = 0";
                else fail('Bad is() filter.');
                break;
            case 'in':
                $values = is_array($value) ? $value : [];
                if (!$values) { $sql[] = '0'; break; }
                $sql[] = "$col IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
                foreach ($values as $v) $params[] = db_value($v, $kind);
                break;
            case 'ilike': $sql[] = "LOWER($col) LIKE LOWER(?)"; $params[] = str_replace('*', '%', (string)$value); break;
            default: fail("Filter $op is not available.", '0A000');
        }
    }
    return $sql ? implode(' AND ', $sql) : '1';
}

function select_rows(string $table, array $q): array
{
    $rule = read_rule($table);
    if ($rule === null) deny("permission denied for table $table");
    $only = parse_columns($table, $q['columns'] ?? '*');
    $params = $rule[1];
    $where = '(' . $rule[0] . ') AND (' . where_from_filters($table, $q['filters'] ?? [], $params) . ')';
    $count = null;
    if (($q['count'] ?? null) === 'exact') $count = (int)val("SELECT COUNT(*) FROM `$table` t WHERE $where", $params);
    if (!empty($q['head'])) return ['data' => null, 'count' => $count];
    $order = [];
    foreach ($q['order'] ?? [] as [$column, $asc]) {
        column_kind($table, (string)$column);
        // PostgreSQL puts NULLs last when ascending and first when descending.
        $order[] = 't.' . quote_ident($column) . ' IS ' . ($asc ? 'NULL' : 'NOT NULL') . ', t.' . quote_ident($column) . ($asc ? ' ASC' : ' DESC');
    }
    $sql = "SELECT t.* FROM `$table` t WHERE $where" . ($order ? ' ORDER BY ' . implode(', ', $order) : '');
    if (isset($q['limit']) && $q['limit'] !== null) $sql .= ' LIMIT ' . max(0, (int)$q['limit']);
    $list = array_map(fn($r) => format_row($table, $r, $only), rows($sql, $params));
    return ['data' => single_or_list($list, $q['single'] ?? null), 'count' => $count];
}
function single_or_list(array $list, ?string $single)
{
    if ($single === 'single') {
        if (count($list) !== 1) fail('JSON object requested, multiple (or no) rows returned', 'PGRST116', 406);
        return $list[0];
    }
    if ($single === 'maybe') {
        if (count($list) > 1) fail('JSON object requested, multiple (or no) rows returned', 'PGRST116', 406);
        return $list[0] ?? null;
    }
    return $list;
}

function write_rows(string $table, string $op, array $q): array
{
    $rule = write_rule($table);
    if ($rule === null) deny("permission denied for table $table");
    $cols = table_columns($table);
    $pk = primary_key($table);
    $returning = array_key_exists('returning', $q) && $q['returning'] !== null ? parse_columns($table, $q['returning']) : false;
    $done = [];

    if ($op === 'insert') {
        $values = $q['values'] ?? [];
        $list = array_is_list($values) ? $values : [$values];
        foreach ($list as $row) {
            if (!is_array($row)) fail('Bad row.');
            $new = insert_defaults($table, $row);
            foreach (array_keys($new) as $c) { if (!isset($cols[$c]) || $cols[$c]['generated']) fail("Column $c cannot be set.", '42703'); }
            $new = before_write($table, 'insert', $new, null, array_keys($new));
            $id = insert_row($table, $new);
            $saved = row("SELECT * FROM `$table` WHERE `$pk` = ?", [$id]);
            require_rule($table, $rule, $saved[$pk]);
            after_write($table, 'insert', $saved, null);
            $done[] = $saved;
        }
    } elseif ($op === 'update') {
        $values = $q['values'] ?? [];
        if (!is_array($values) || !$values || array_is_list($values)) fail('Nothing to change.');
        foreach (array_keys($values) as $c) { if (!isset($cols[$c]) || $cols[$c]['generated'] || $c === $pk) fail("Column $c cannot be changed.", '42703'); }
        foreach (matched_rows($table, $rule, $q['filters'] ?? []) as $old) {
            $new = before_write($table, 'update', array_merge($old, $values), $old, array_keys($values));
            update_row($table, $old[$pk], $new, $old);
            $saved = row("SELECT * FROM `$table` WHERE `$pk` = ?", [$old[$pk]]);
            require_rule($table, $rule, $saved[$pk]);
            after_write($table, 'update', $saved, $old);
            $done[] = $saved;
        }
    } else { // delete
        foreach (matched_rows($table, $rule, $q['filters'] ?? []) as $old) {
            before_delete($table, $old);
            q("DELETE FROM `$table` WHERE `$pk` = ?", [$old[$pk]]);
            after_write($table, 'delete', null, $old);
            $done[] = $old;
        }
    }
    if ($returning === false) return ['data' => null];
    $list = array_map(fn($r) => format_row($table, $r, $returning), $done);
    return ['data' => single_or_list($list, $q['single'] ?? null)];
}
function matched_rows(string $table, array $rule, array $filters): array
{
    $params = $rule[1];
    $where = '(' . $rule[0] . ') AND (' . where_from_filters($table, $filters, $params) . ')';
    return rows("SELECT t.* FROM `$table` t WHERE $where FOR UPDATE", $params);
}
function require_rule(string $table, array $rule, $id): void
{
    $pk = primary_key($table);
    if (!val("SELECT 1 FROM `$table` t WHERE t.`$pk` = ? AND ({$rule[0]})", array_merge([$id], $rule[1]))) {
        fail("new row violates row-level security policy for table \"$table\"", '42501', 403);
    }
}
// Raw insert / update of prepared values (no rules): used by the endpoint above
// and by server functions.
function insert_row(string $table, array $row)
{
    $cols = table_columns($table);
    $names = []; $params = [];
    foreach ($row as $c => $v) {
        if (!isset($cols[$c]) || $cols[$c]['generated']) continue;
        $names[] = quote_ident($c); $params[] = db_value($v, $cols[$c]['kind']);
    }
    q("INSERT INTO `$table` (" . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')', $params);
    $pk = primary_key($table);
    return $row[$pk] ?? db()->lastInsertId();
}
function update_row(string $table, $id, array $new, ?array $old = null): void
{
    $cols = table_columns($table);
    $sets = []; $params = [];
    foreach ($new as $c => $v) {
        if (!isset($cols[$c]) || $cols[$c]['generated'] || $c === primary_key($table)) continue;
        $value = db_value($v, $cols[$c]['kind']);
        if ($old !== null && array_key_exists($c, $old) && same_value($old[$c], $value, $cols[$c]['kind'])) continue;
        $sets[] = quote_ident($c) . ' = ?'; $params[] = $value;
    }
    if (!$sets) return;
    $params[] = $id;
    q("UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE `' . primary_key($table) . '` = ?', $params);
}
function same_value($stored, $new, string $kind): bool
{
    if ($stored === null || $new === null) return $stored === $new;
    if ($kind === 'number') return abs((float)$stored - (float)$new) < 1e-9;
    if ($kind === 'int' || $kind === 'bool') return (int)$stored === (int)$new;
    if ($kind === 'timestamp' || $kind === 'timestamptz') return substr((string)$stored, 0, 26) === substr(str_pad((string)$new, 26, '0'), 0, 26) || rtrim((string)$stored, '0.') === rtrim((string)$new, '0.');
    return (string)$stored === (string)$new;
}
