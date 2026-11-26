<?php
// Forgot password by email, and each account's email (members, club
// administrators, Super Admin). One email per account; used only after its
// confirmation link is opened. Links work once and are stored as SHA-256 hashes.
// Emails are sent with Resend (config: resend_api_key, reset_from, site_url).
declare(strict_types=1);

function token_hash(string $token): string { return hash('sha256', $token); }

// A new link for a member (member_id) or another account (user_id); older unused
// ones of the same kind stop working. Null when 3 were made in the last 24 hours.
function email_token_new(?int $memberId, ?string $userId, string $kind, string $email, string $valid): ?string
{
    $owner = $memberId !== null ? ['member_id = ?', $memberId] : ['user_id = ?', $userId];
    $since = gmdate('Y-m-d H:i:s', time() - 86400);
    if ((int)val("SELECT COUNT(*) FROM account_email_tokens WHERE {$owner[0]} AND kind = ? AND created_at > ?", [$owner[1], $kind, $since]) >= 3) return null;
    q("UPDATE account_email_tokens SET used_at = ? WHERE {$owner[0]} AND kind = ? AND used_at IS NULL", [now_utc(), $owner[1], $kind]);
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO account_email_tokens (member_id, user_id, kind, token_hash, email, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$memberId, $memberId !== null ? null : $userId, $kind, token_hash($token), $email, gmdate('Y-m-d H:i:s', strtotime($valid))]);
    return $token;
}
// An account's email: a member account's is on the member record.
function account_email(string $userId): ?array
{
    $r = row('SELECT p.role, p.username, p.email AS p_email, p.email_confirmed_at AS p_conf, m.id AS member_id, m.email AS m_email, m.email_confirmed_at AS m_conf, m.full_name
                FROM app_profiles p LEFT JOIN members m ON m.id = p.member_id WHERE p.id = ?', [$userId]);
    if (!$r) return null;
    $isMember = $r['role'] === 'member';
    return [
        'email' => $isMember ? $r['m_email'] : $r['p_email'],
        'confirmed' => ($isMember ? $r['m_conf'] : $r['p_conf']) !== null,
        'member_id' => $isMember ? (int)$r['member_id'] : null,
        'name' => $r['full_name'] ?? $r['username'],
    ];
}

function password_reset_functions(): array
{
    return [
        'account_email_status' => function ($a) {
            $p = require_signed_in();
            $e = account_email($p['id']);
            return ['email' => $e['email'], 'confirmed' => $e['confirmed']];
        },
        'account_set_email' => fn($a) => account_set_email($a['p_email'] ?? null),
        'member_set_email' => fn($a) => account_set_email($a['p_email'] ?? null),
        'confirm_member_email' => function ($a) {
            return transaction(function () use ($a) {
                $t = row("SELECT * FROM account_email_tokens WHERE token_hash = ? AND kind = 'confirm' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE", [token_hash((string)($a['p_token'] ?? ''))]);
                $still = $t && ($t['member_id'] !== null
                    ? val('SELECT 1 FROM members WHERE id = ? AND email = ?', [$t['member_id'], $t['email']])
                    : val('SELECT 1 FROM app_profiles WHERE id = ? AND email = ?', [$t['user_id'], $t['email']]));
                if (!$still) fail('This confirmation link has expired or was already used.');
                if ($t['member_id'] !== null) q('UPDATE members SET email_confirmed_at = ? WHERE id = ?', [now_utc(), $t['member_id']]);
                else q('UPDATE app_profiles SET email_confirmed_at = ? WHERE id = ?', [now_utc(), $t['user_id']]);
                q('UPDATE account_email_tokens SET used_at = ? WHERE id = ?', [now_utc(), $t['id']]);
                return ['email' => $t['email']];
            });
        },
        'password_reset_complete' => function ($a) {
            $password = (string)($a['p_password'] ?? '');
            if (strlen($password) < 8) fail('Choose a password with at least 8 characters.');
            if (strlen($password) > 72) fail('Choose a password of at most 72 characters.');
            return transaction(function () use ($a, $password) {
                $t = row("SELECT * FROM account_email_tokens WHERE token_hash = ? AND kind = 'reset' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE", [token_hash((string)($a['p_token'] ?? ''))]);
                $p = null;
                if ($t) $p = $t['member_id'] !== null
                    ? row("SELECT * FROM app_profiles WHERE member_id = ? AND role = 'member' AND is_active = 1", [$t['member_id']])
                    : row('SELECT * FROM app_profiles WHERE id = ? AND is_active = 1', [$t['user_id']]);
                $e = $p ? account_email($p['id']) : null;
                if (!$t || !$p || !$e || $e['email'] !== $t['email'] || !$e['confirmed']) fail('This reset link has expired or was already used. Ask for a new one.');
                set_password($p['id'], $password, true);   // also signs the account out everywhere
                q("UPDATE account_email_tokens SET used_at = ? WHERE kind = 'reset' AND used_at IS NULL AND " . ($t['member_id'] !== null ? 'member_id = ?' : 'user_id = ?'),
                    [now_utc(), $t['member_id'] ?? $t['user_id']]);
                q('DELETE FROM account_devices WHERE user_id = ?', [$p['id']]);
                if ($p['club_id'] !== null) {
                    q('INSERT INTO audit_log (club_id, table_name, row_id, action, changed_by, changed_by_name, new_data) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$p['club_id'], 'password_reset', $e['member_id'], 'update', $p['id'], $p['username'],
                         json_encode(['event' => 'Password reset by email', 'member' => $e['name'], 'email' => $t['email']], JSON_UNESCAPED_UNICODE)]);
                }
                return ['username' => $p['username'], 'role' => $p['role']];
            });
        },
    ];
}

function account_set_email($value): array
{
    $p = require_signed_in();
    $email = strtolower(trim((string)($value ?? '')));
    $email = $email === '' ? null : $email;
    if (!email_ok($email)) fail('Enter a valid email address.');
    return transaction(function () use ($p, $email) {
        q('SELECT GET_LOCK(?, 5)', ['avilonx.email']);
        try {
            if ($p['role'] === 'member') {
                $old = row('SELECT * FROM members WHERE id = ? FOR UPDATE', [$p['member_id']]);
                $new = email_rules('members', array_merge($old, ['email' => $email]), $old);
                q('UPDATE members SET email = ?, email_confirmed_at = ? WHERE id = ?', [$new['email'], $new['email_confirmed_at'], $old['id']]);
            } else {
                $old = row('SELECT * FROM app_profiles WHERE id = ? FOR UPDATE', [$p['id']]);
                $new = email_rules('app_profiles', array_merge($old, ['email' => $email]), $old);
                q('UPDATE app_profiles SET email = ?, email_confirmed_at = ? WHERE id = ?', [$new['email'], $new['email_confirmed_at'], $old['id']]);
            }
        } finally {
            q('SELECT RELEASE_LOCK(?)', ['avilonx.email']);
        }
        $e = account_email($p['id']);
        return ['email' => $e['email'], 'confirmed' => $e['confirmed']];
    });
}

// ---- The "avilonx-password-reset" function (sends the emails) ----------------------------
function function_password_reset(array $body): array
{
    if (!config('resend_api_key')) fail('Password reset email is not set up yet (resend_api_key in api/config.php).', 'NOT_SET_UP', 503);
    $site = rtrim((string)config('site_url'), '/');
    if (($body['action'] ?? '') === 'reset') {
        $username = strtolower(trim((string)($body['username'] ?? '')));
        if (preg_match(USERNAME_RE, $username)) {
            try {
                $link = transaction(function () use ($username) {
                    $p = row('SELECT * FROM app_profiles WHERE username = ? AND is_active = 1', [$username]);
                    if (!$p) return null;
                    $e = account_email($p['id']);
                    if (!$e || !$e['email'] || !$e['confirmed']) return null;
                    $token = email_token_new($e['member_id'], $e['member_id'] === null ? $p['id'] : null, 'reset', $e['email'], '+1 hour');
                    return $token ? ['token' => $token, 'email' => $e['email'], 'name' => $e['name'], 'username' => $p['username']] : null;
                });
                if ($link) {
                    send_email($link['email'], 'Reset your AvilonX password', email_html('Reset your password', [
                        'Hi ' . htmlspecialchars((string)$link['name']) . ',',
                        'Someone asked to reset the password of your AvilonX account <strong>' . htmlspecialchars($link['username']) . '</strong>. Tap the button to choose a new password.',
                    ], 'Choose a new password', "$site/pages/reset-password/index.html?reset={$link['token']}",
                        'The link works once, for 1 hour. If you did not ask for this, ignore this email: your password stays the same.'));
                }
            } catch (Throwable $e) {
                error_log('AvilonX password reset: ' . $e->getMessage());
            }
        }
        return ['ok' => true];   // the same answer for every username
    }
    if (($body['action'] ?? '') === 'confirm') {
        $p = require_signed_in();
        $memberId = isset($body['member_id']) && $body['member_id'] !== null ? (int)$body['member_id'] : null;
        $link = transaction(function () use ($p, $memberId) {
            if ($memberId === null) {
                $e = account_email($p['id']);
            } else {
                $m = row('SELECT * FROM members WHERE id = ?', [$memberId]);
                if (!$m || !(($p['role'] === 'member' && (int)$p['member_id'] === $memberId) || (in_array($p['role'], ['admin', 'staff'], true) && (string)$p['club_id'] === (string)$m['club_id']))) deny();
                $e = ['email' => $m['email'], 'confirmed' => $m['email_confirmed_at'] !== null, 'member_id' => (int)$m['id'], 'name' => $m['full_name']];
            }
            if (!$e['email']) fail('No email saved yet.');
            if ($e['confirmed']) return ['confirmed' => true, 'email' => $e['email']];
            $token = email_token_new($e['member_id'], $e['member_id'] === null ? $p['id'] : null, 'confirm', $e['email'], '+3 days');
            if (!$token) fail('Three confirmation emails were already sent today. Try again tomorrow.');
            return ['token' => $token, 'email' => $e['email'], 'name' => $e['name']];
        });
        if (!empty($link['confirmed'])) return ['ok' => true, 'confirmed' => true, 'email' => $link['email']];
        send_email($link['email'], 'Confirm your email for AvilonX', email_html('Confirm your email', [
            'Hi ' . htmlspecialchars((string)$link['name']) . ',',
            'This email was added to your AvilonX account for password resets. Tap the button to confirm it.',
        ], 'Confirm my email', "$site/pages/reset-password/index.html?confirm={$link['token']}", "The link works for 3 days. If you don't know AvilonX, ignore this email."));
        return ['ok' => true, 'sent' => true, 'email' => $link['email']];
    }
    fail('Unknown action');
}

function email_html(string $title, array $lines, string $button, string $link, string $foot): string
{
    $l = htmlspecialchars($link);
    $body = implode('', array_map(fn($x) => "<p style=\"margin:0 0 12px;font-size:15px;line-height:1.5\">$x</p>", $lines));
    return "<!doctype html><html><body style=\"margin:0;padding:24px;background:#f3f6f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b\">
<table role=\"presentation\" width=\"100%\" style=\"max-width:480px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px\"><tr><td style=\"padding:28px\">
<p style=\"margin:0 0 4px;font-size:12px;letter-spacing:.08em;color:#64748b\">AVILONX RACE CLOCKING</p>
<h1 style=\"margin:0 0 16px;font-size:20px\">" . htmlspecialchars($title) . "</h1>$body
<p style=\"margin:20px 0\"><a href=\"$l\" style=\"display:inline-block;padding:12px 22px;background:#00345f;color:#fff;border-radius:8px;text-decoration:none;font-weight:bold\">" . htmlspecialchars($button) . "</a></p>
<p style=\"margin:0 0 12px;font-size:13px;color:#64748b;line-height:1.5\">$foot</p>
<p style=\"margin:0;font-size:12px;color:#94a3b8;word-break:break-all\">$l</p>
</td></tr></table></body></html>";
}
function send_email(string $to, string $subject, string $html): void
{
    if (!function_exists('curl_init')) fail('PHP curl is not enabled (php.ini: extension=curl).', 'NOT_SET_UP', 503);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . config('resend_api_key'), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['from' => config('reset_from'), 'to' => [$to], 'subject' => $subject, 'html' => $html]),
    ]);
    $out = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false || $status >= 300) {
        error_log("AvilonX email: Resend $status $err $out");
        fail('Could not send the email (Resend). Check resend_api_key and the domain in Resend.', 'EMAIL', 502);
    }
}
