<?php
// Uploaded files (bird photos, clocking videos, club logos), with the same
// rules as the Supabase storage buckets. Files are kept in config storage_dir;
// pages get signed links (valid for a limited time) to show them.
declare(strict_types=1);

const BUCKETS = [
    'pigeon-photos' => ['max' => 6 * 1024 * 1024, 'types' => ['image/jpeg', 'image/png', 'image/webp'], 'public' => false],
    'clock-videos' => ['max' => 60 * 1024 * 1024, 'types' => ['video/mp4', 'video/webm', 'video/quicktime'], 'public' => false],
    'club-logos' => ['max' => 2 * 1024 * 1024, 'types' => ['image/png', 'image/jpeg', 'image/webp'], 'public' => true],
];

function storage_path(string $bucket, string $path): string
{
    if (!isset(BUCKETS[$bucket])) fail('Unknown bucket.', '22023', 404);
    if (!preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-.]+)*$#', $path) || str_contains($path, '..')) fail('Bad file path.');
    $dir = rtrim((string)config('storage_dir'), '/\\');
    return "$dir/$bucket/$path";
}
function path_folders(string $path): array { return explode('/', $path); }

function may_write_file(string $bucket, string $path): bool
{
    $f = path_folders($path); $club = (string)current_club_id();
    if (!profile() || $club === '') return false;
    switch ($bucket) {
        case 'pigeon-photos': return can_manage_club_data() && $f[0] === $club;
        case 'clock-videos': return current_member_id() !== null && $f[0] === $club && ($f[1] ?? '') === (string)current_member_id();
        case 'club-logos': return is_app_admin() && $f[0] === $club;
    }
    return false;
}
function may_read_file(string $bucket, string $path): bool
{
    if (BUCKETS[$bucket]['public'] ?? false) return true;
    if (!profile()) return false;
    $f = path_folders($path); $club = (string)current_club_id();
    if (can_manage_club_data() && $club !== '' && $f[0] === $club) return true;
    if ($bucket === 'pigeon-photos') return (bool)val('SELECT 1 FROM pigeons WHERE photo_path = ? AND member_id = ?', [$path, current_member_id()]);
    if ($bucket === 'clock-videos') return $club !== '' && $f[0] === $club && ($f[1] ?? '') === (string)current_member_id();
    return false;
}

function storage_upload(): array
{
    $bucket = (string)($_POST['bucket'] ?? ''); $path = (string)($_POST['path'] ?? '');
    $file = storage_path($bucket, $path);
    if (!may_write_file($bucket, $path)) fail('new row violates row-level security policy', '42501', 403);
    $up = $_FILES['file'] ?? null;
    if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK) {
        fail(($up['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'The file is too big for this server (php.ini upload_max_filesize).' : 'Upload failed.');
    }
    $rules = BUCKETS[$bucket];
    if ($up['size'] > $rules['max']) fail('The file is too big.', '413', 413);
    $type = (function_exists('mime_content_type') ? mime_content_type($up['tmp_name']) : '') ?: (string)($_POST['content_type'] ?? '');
    if (!in_array($type, $rules['types'], true)) fail("This file type is not allowed ($type).");
    if (is_file($file) && ($_POST['upsert'] ?? '0') !== '1') fail('The resource already exists', '409', 409);
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true)) fail('Cannot save the file: check the uploads folder permissions.', 'XX000', 500);
    if (!move_uploaded_file($up['tmp_name'], $file)) fail('Cannot save the file: check the uploads folder permissions.', 'XX000', 500);
    return ['path' => $path, 'fullPath' => "$bucket/$path"];
}
function storage_remove(array $body): array
{
    $bucket = (string)($body['bucket'] ?? ''); $removed = [];
    foreach ((array)($body['paths'] ?? []) as $path) {
        $file = storage_path($bucket, (string)$path);
        if (!may_write_file($bucket, (string)$path)) continue;
        if (is_file($file) && unlink($file)) $removed[] = ['name' => $path];
    }
    return $removed;
}
function file_token(string $bucket, string $path, int $expires): string
{
    return $expires . '.' . hash_hmac('sha256', "$bucket|$path|$expires", (string)config('app_secret'));
}
function storage_sign(array $body): array
{
    $bucket = (string)($body['bucket'] ?? ''); $seconds = max(60, min(7 * 86400, (int)($body['expires_in'] ?? 3600)));
    $out = [];
    foreach ((array)($body['paths'] ?? []) as $path) {
        $path = (string)$path;
        $file = storage_path($bucket, $path);
        $ok = is_file($file) && may_read_file($bucket, $path);
        $out[] = ['path' => $path, 'token' => $ok ? file_token($bucket, $path, time() + $seconds) : null];
    }
    return $out;
}
// GET: sends the file when the link is signed (or the bucket is public).
function storage_file(): never
{
    $bucket = (string)($_GET['bucket'] ?? ''); $path = (string)($_GET['path'] ?? '');
    $file = storage_path($bucket, $path);
    $token = (string)($_GET['token'] ?? '');
    $ok = BUCKETS[$bucket]['public'];
    if (!$ok && preg_match('/^(\d+)\.([0-9a-f]{64})$/', $token, $m)) {
        $ok = (int)$m[1] >= time() && hash_equals(file_token($bucket, $path, (int)$m[1]), $token);
    }
    if (!$ok || !is_file($file)) { http_response_code(404); exit('Not found'); }
    $byExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];
    $type = (function_exists('mime_content_type') ? mime_content_type($file) : '') ?: ($byExt[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream');
    $size = filesize($file);
    header('Content-Type: ' . $type);
    header('Cache-Control: ' . (BUCKETS[$bucket]['public'] ? 'public, max-age=300' : 'private, max-age=3600'));
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    // Videos need byte ranges for seeking on phones.
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $r)) {
        $start = $r[1] === '' ? max(0, $size - (int)$r[2]) : (int)$r[1];
        $end = $r[2] === '' || $r[1] === '' ? $size - 1 : min((int)$r[2], $size - 1);
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
        header('Content-Length: ' . ($end - $start + 1));
        $h = fopen($file, 'rb'); fseek($h, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($h)) { $chunk = fread($h, min(65536, $left)); echo $chunk; $left -= strlen($chunk); }
        fclose($h);
        exit;
    }
    header('Content-Length: ' . $size);
    readfile($file);
    exit;
}
