<?php
// Column types of each table (read from MySQL), and how values are sent to the
// pages and read from them.
declare(strict_types=1);

// Times stored without a zone in the Supabase version: sent as "2026-11-26T10:00:00".
const NO_ZONE_COLUMNS = ['release_at_utc', 'received_at_utc', 'server_received_at_utc'];
// Kept as JSON text.
const JSON_COLUMNS = ['race_entries.remarks', 'audit_log.old_data', 'audit_log.new_data'];
// Never sent to the pages.
const HIDDEN_COLUMNS = ['barcode_stickers.scratch_code', 'pigeons.active_nfc_tag', 'rfid_devices.key_hash'];

function table_columns(string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $list = rows('SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS ctype, EXTRA AS extra, IS_NULLABLE AS nullable
                        FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
        if (!$list) fail("Unknown table $table", 'PGRST205', 404);
        $cols = [];
        foreach ($list as $c) {
            $kind = 'text';
            $t = strtolower($c['type']);
            if ($t === 'tinyint' && str_contains(strtolower($c['ctype']), 'tinyint(1)')) $kind = 'bool';
            elseif (in_array($t, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'], true)) $kind = 'int';
            elseif (in_array($t, ['decimal', 'double', 'float'], true)) $kind = 'number';
            elseif ($t === 'datetime' || $t === 'timestamp') $kind = in_array($c['name'], NO_ZONE_COLUMNS, true) ? 'timestamp' : 'timestamptz';
            elseif ($t === 'date') $kind = 'date';
            if (in_array("$table.{$c['name']}", JSON_COLUMNS, true)) $kind = 'json';
            $cols[$c['name']] = [
                'kind' => $kind,
                'generated' => stripos($c['extra'], 'GENERATED') !== false || stripos($c['extra'], 'auto_increment') !== false,
                'hidden' => in_array("$table.{$c['name']}", HIDDEN_COLUMNS, true),
            ];
        }
        $cache[$table] = $cols;
    }
    return $cache[$table];
}

function format_value($value, string $kind)
{
    if ($value === null) return null;
    switch ($kind) {
        case 'bool': return (bool)(int)$value;
        case 'int': return (int)$value;
        case 'number': return (float)$value;
        case 'json': $d = json_decode((string)$value, true); return $d === null && $value !== 'null' ? $value : $d;
        case 'date': return substr((string)$value, 0, 10);
        case 'timestamp':
        case 'timestamptz':
            $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', strlen((string)$value) > 19 ? (string)$value : $value . '.000000', new DateTimeZone('UTC'));
            if (!$d) return $value;
            $micro = (int)$d->format('u');
            $base = $d->format('Y-m-d\TH:i:s') . ($micro ? '.' . rtrim($d->format('u'), '0') : '');
            return $kind === 'timestamptz' ? $base . '+00:00' : $base;
    }
    return $value;
}
function format_row(string $table, ?array $row, ?array $only = null): ?array
{
    if ($row === null) return null;
    $cols = table_columns($table);
    $out = [];
    foreach ($row as $name => $value) {
        if (!isset($cols[$name]) || $cols[$name]['hidden']) continue;
        if ($only !== null && !in_array($name, $only, true)) continue;
        $out[$name] = format_value($value, $cols[$name]['kind']);
    }
    return $out;
}
// A value from the pages → what MySQL stores.
function db_value($value, string $kind)
{
    if ($value === null) return null;
    switch ($kind) {
        case 'bool': return is_string($value) ? (in_array(strtolower($value), ['true', 't', '1', 'yes'], true) ? 1 : 0) : ($value ? 1 : 0);
        case 'int': if ($value === '') return null; if (!is_numeric($value)) fail("Invalid number: $value"); return (int)$value;
        case 'number': if ($value === '') return null; if (!is_numeric($value)) fail("Invalid number: $value"); return (string)$value;
        case 'json': return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        case 'timestamp':
        case 'timestamptz': return is_string($value) ? to_utc($value) : fail('Invalid date/time.');
        case 'date': if ($value === '') return null; if (!preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$value)) fail("Invalid date: $value"); return substr((string)$value, 0, 10);
    }
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE);
    if (is_bool($value)) return $value ? 'true' : 'false';
    return (string)$value;
}
function column_kind(string $table, string $column): string
{
    $cols = table_columns($table);
    // Hidden columns (secret codes, keys) cannot be read, filtered or sorted on.
    if (!isset($cols[$column]) || $cols[$column]['hidden']) fail("Column $column does not exist on $table", '42703');
    return $cols[$column]['kind'];
}
function quote_ident(string $name): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) fail("Bad name: $name", '42703');
    return "`$name`";
}
