<?php
// Core of the AvilonX PHP API: settings, database, errors, JSON, sign-in.
declare(strict_types=1);

// ---- Settings ------------------------------------------------------------------
function config(?string $key = null)
{
    static $config;
    if ($config === null) {
        $defaults = require __DIR__ . '/../config.sample.php';
        $file = __DIR__ . '/../config.php';
        $config = is_file($file) ? array_merge($defaults, require $file) : $defaults;
        $env = getenv('AVILONX_CONFIG');
        if ($env && is_file($env)) $config = array_merge($config, require $env);
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

// ---- Errors ------------------------------------------------------------------------
// Error codes follow PostgreSQL's (the pages know them): 22023 bad value,
// 23505 already in use, 23503 still used, 23514 rule broken, 42501 not allowed.
final class ApiError extends Exception
{
    public string $sqlstate;
    public int $status;
    public function __construct(string $message, string $sqlstate = '22023', int $status = 400)
    {
        parent::__construct($message);
        $this->sqlstate = $sqlstate;
        $this->status = $status;
    }
}
function fail(string $message, string $code = '22023', int $status = 400): never
{
    throw new ApiError($message, $code, $status);
}
function deny(string $message = 'Not allowed.'): never
{
    throw new ApiError($message, '42501', 403);
}

// MySQL errors → the same codes and messages the pages expect.
function translate_db_error(PDOException $e): ApiError
{
    $driver = (int)($e->errorInfo[1] ?? 0);
    $text = (string)($e->errorInfo[2] ?? $e->getMessage());
    if ($driver === 1062) {
        preg_match("/for key '(?:[^.']+\\.)?([^']+)'/", $text, $m);
        return new ApiError('duplicate key value violates unique constraint "' . ($m[1] ?? 'unique') . '"', '23505', 409);
    }
    if ($driver === 1451) return new ApiError('This record is still in use by another item.', '23503', 409);
    if ($driver === 1452) return new ApiError('A linked record was not found.', '23503', 409);
    if ($driver === 4025 || $driver === 3819) {
        preg_match('/constraint [`\'"]?([A-Za-z0-9_]+)/i', $text, $m);
        return new ApiError('new row violates check constraint "' . ($m[1] ?? 'check') . '"', '23514', 400);
    }
    if ($driver === 1048) return new ApiError('A required value is missing: ' . $text, '23502', 400);
    if ($driver === 1406) return new ApiError('A value is too long: ' . $text, '22001', 400);
    error_log('AvilonX database error: ' . $e->getMessage());
    return new ApiError('Database error. Check that database/avilonx.sql was imported.', 'XX000', 500);
}

// ---- Database ---------------------------------------------------------------------
function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', config('db_host'), (int)config('db_port'), config('db_name'));
        try {
            $pdo = new PDO($dsn, (string)config('db_user'), (string)config('db_pass'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            error_log('AvilonX: cannot connect to MySQL: ' . $e->getMessage());
            fail('Cannot connect to the database. Check api/config.php (database name, user, password) and that MySQL is running.', 'XX000', 500);
        }
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute(array_values($params) === $params ? $params : $params);
        return $stmt;
    } catch (PDOException $e) {
        throw translate_db_error($e);
    }
}
function rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function row(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $params = []) { $v = q($sql, $params)->fetchColumn(); return $v === false ? null : $v; }
function transaction(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function now_utc(): string { return gmdate('Y-m-d H:i:s') . sprintf('.%06d', (int)((microtime(true) * 1e6) % 1e6)); }
function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
// Any date/time from the pages (ISO with Z or an offset, or plain UTC) → UTC for MySQL.
function to_utc(?string $value): ?string
{
    if ($value === null || trim($value) === '') return null;
    $text = trim($value);
    $hasZone = (bool)preg_match('/([zZ]|[+-]\d\d:?\d\d)$/', $text);
    try {
        $d = new DateTimeImmutable($text, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        fail("Invalid date/time: $value");
    }
    if ($hasZone) $d = $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s.u');
}

// ---- Sign-in (PHP session) ---------------------------------------------------------
const USERNAME_RE = '/^[a-z0-9][a-z0-9_.-]{2,31}$/';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('avilonx_session');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// The signed-in account (app_profiles row), or null. A password reset or a
// "sign out everywhere" raises session_version, which ends older sessions.
function profile(): ?array
{
    static $loaded = false, $profile = null;
    if ($loaded) return $profile;
    $loaded = true;
    start_session();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;
    $p = row('SELECT p.*, u.session_version FROM app_profiles p JOIN auth_users u ON u.id = p.id WHERE p.id = ?', [$uid]);
    if (!$p || (int)$p['session_version'] !== (int)($_SESSION['ver'] ?? 0)) {
        $_SESSION = [];
        return null;
    }
    $profile = $p;
    return $profile;
}
function forget_profile_cache(): void { /* profile() is cached per request; new requests reload it. */ }
function uid(): ?string { return profile()['id'] ?? null; }
function role(): ?string { return profile()['role'] ?? null; }
function current_club_id(): ?int { $c = profile()['club_id'] ?? null; return $c === null ? null : (int)$c; }
function current_member_id(): ?int { $m = profile()['member_id'] ?? null; return $m === null ? null : (int)$m; }
function is_superadmin(): bool { return role() === 'superadmin'; }
function is_app_admin(): bool { return role() === 'admin'; }
function can_manage_club_data(): bool { return in_array(role(), ['admin', 'staff'], true); }
function require_signed_in(): array { $p = profile(); if (!$p) deny('Sign in first.'); return $p; }
function require_superadmin(): void { if (!is_superadmin()) deny('Superadmin account required.'); }
function require_app_admin(): void { if (!is_app_admin()) deny('Club administrator account required.'); }

function hash_password(string $password): string { return password_hash($password, PASSWORD_BCRYPT); }
function check_new_password(?string $password, string $message = 'Password must be at least 8 characters.'): string
{
    if ($password === null || strlen($password) < 8) fail($message);
    if (strlen($password) > 72) fail('Choose a password of at most 72 characters.');
    return $password;
}
function normalize_username(?string $username): string
{
    $u = strtolower(trim((string)$username));
    if (!preg_match(USERNAME_RE, $u)) fail('Username must be 3–32 letters, numbers, dots, hyphens or underscores.');
    return $u;
}
// A new sign-in account; returns its id.
function create_auth_user(string $username, ?string $password): string
{
    $username = normalize_username($username);
    $password = check_new_password($password);
    if (val('SELECT 1 FROM app_profiles WHERE username = ?', [$username])) fail('That username is already in use.', '23505', 409);
    $id = uuid4();
    q('INSERT INTO auth_users (id, password_hash) VALUES (?, ?)', [$id, hash_password($password)]);
    return $id;
}
function set_password(string $userId, string $password, bool $signOutEverywhere = false): void
{
    q('UPDATE auth_users SET password_hash = ?, updated_at = ?' . ($signOutEverywhere ? ', session_version = session_version + 1' : '') . ' WHERE id = ?',
        [hash_password($password), now_utc(), $userId]);
}

// ---- JSON in and out -----------------------------------------------------------------
function input(): array
{
    static $body;
    if ($body === null) {
        $raw = file_get_contents('php://input');
        $body = $raw === '' || $raw === false ? [] : json_decode($raw, true);
        if (!is_array($body)) $body = [];
    }
    return $body;
}
function send_json($payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $later = function_exists('push_has_pending') && push_has_pending();
    if ($later) { header('Content-Length: ' . strlen($json)); header('Connection: close'); }
    echo $json;
    // Notifications are sent after the answer has gone back to the page.
    if ($later) {
        ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { while (ob_get_level() > 0) ob_end_flush(); flush(); }
        push_flush_pending();
    }
    exit;
}
