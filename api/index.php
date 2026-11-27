<?php
// AvilonX PHP API: every request from the website comes here
// (api/index.php?r=<route>). See docs/ARCHITECTURE-PHP.md.
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/query.php';
require __DIR__ . '/lib/hooks.php';
require __DIR__ . '/lib/password_reset.php';
require __DIR__ . '/lib/rpc.php';
require __DIR__ . '/lib/rpc_clocking.php';
require __DIR__ . '/lib/rpc_readers.php';
require __DIR__ . '/lib/storage.php';
require __DIR__ . '/lib/push.php';
require __DIR__ . '/lib/rpc_extra.php';
require __DIR__ . '/lib/billing.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$route = (string)($_GET['r'] ?? '');
// Supabase-style address (/rest/v1/rpc/<name>), used by assets/js/config.js
// callers (landing page, error reports): answered in Supabase's format, the
// result itself or { message, code }.
$rawRpc = null;
if (preg_match('#^/?rest/v1/rpc/([a-z0-9_]+)$#', $route, $m)) { $rawRpc = $m[1]; $route = 'rpc/' . $m[1]; }
try {
    // Files are opened by links (GET), everything else is POST.
    if ($route === 'storage/file') storage_file();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') send_json(['ok' => true, 'service' => 'AvilonX API'], 200);
    // Requests must come from this site (blocks other sites posting with the cookie).
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) deny('Request from another site.');

    $body = input();
    switch (true) {
        case $route === 'query':
            send_json(run_query($body));
        case $route === 'auth/session':
            $p = profile();
            send_json(['data' => ['session' => $p ? ['user' => ['id' => $p['id']]] : null]]);
        case $route === 'auth/login':
            send_json(['data' => login($body)]);
        case $route === 'auth/logout':
            start_session();
            $_SESSION = [];
            session_destroy();
            send_json(['data' => null]);
        case $route === 'auth/password':
            $p = require_signed_in();
            set_password($p['id'], check_new_password($body['password'] ?? null, 'Choose a password with at least 8 characters.'));
            send_json(['data' => ['user' => ['id' => $p['id']]]]);
        case str_starts_with($route, 'rpc/'):
            $name = substr($route, 4);
            $functions = rpc_functions();
            if (!isset($functions[$name])) fail("Could not find the function public.$name in the schema cache", 'PGRST202', 404);
            $result = $functions[$name]($body);
            if ($rawRpc !== null) send_json($result);
            send_json(['data' => $result]);
        case $route === 'storage/upload':
            send_json(['data' => storage_upload()]);
        case $route === 'storage/remove':
            send_json(['data' => storage_remove($body)]);
        case $route === 'storage/sign':
            send_json(['data' => storage_sign($body)]);
        case $route === 'functions/avilonx-password-reset':
            send_json(['data' => function_password_reset($body)]);
        case $route === 'functions/avilonx-push':
            send_json(['data' => function_push($body)]);
    }
    fail('Not found', 'PGRST000', 404);
} catch (ApiError $e) {
    if ($rawRpc !== null) send_json(['message' => $e->getMessage(), 'code' => $e->sqlstate], $e->status);
    send_json(['error' => ['message' => $e->getMessage(), 'code' => $e->sqlstate]], $e->status);
} catch (Throwable $e) {
    error_log('AvilonX API error: ' . $e);
    send_json(['error' => ['message' => 'Server error. See the PHP error log.', 'code' => 'XX000']], 500);
}

function login(array $body): array
{
    $username = strtolower(trim((string)($body['username'] ?? '')));
    $password = (string)($body['password'] ?? '');
    $user = preg_match(USERNAME_RE, $username)
        ? row('SELECT u.id, u.password_hash, u.session_version FROM app_profiles p JOIN auth_users u ON u.id = p.id WHERE p.username = ?', [$username])
        : null;
    // The same answer (and about the same time) whether the username exists or not.
    $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforsaltasdfghjklqwertyuiopzxcvbnmasdfg';
    if (!password_verify($password, $hash) || !$user) {
        usleep(300000);
        fail('Invalid login credentials', 'invalid_credentials', 400);
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        q('UPDATE auth_users SET password_hash = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];
    $_SESSION['ver'] = (int)$user['session_version'];
    return ['session' => ['user' => ['id' => $user['id']]]];
}
