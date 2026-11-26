<?php
// AvilonX (PHP + MySQL) settings.
//
// Copy this file to config.php (same folder) and change the values. config.php
// is not saved in Git, so passwords and keys stay on your computer / server.
// Without config.php these XAMPP defaults are used.
return [
    // MySQL / MariaDB (XAMPP: user "root", no password).
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'avilonx',
    'db_user' => 'root',
    'db_pass' => '',

    // A long random text used to sign links to photos and videos. Change it
    // once (any 40+ letters and numbers) and keep it secret.
    'app_secret' => 'change-me-to-a-long-random-text',

    // Where uploaded photos, videos and logos are kept. Default: the
    // "uploads" folder next to "api" (it has its own .htaccess blocking direct
    // access). On hosting, a folder outside public_html is even better.
    'storage_dir' => __DIR__ . '/../uploads',

    // Address of the site, used in emails (no slash at the end), e.g.
    // http://localhost/avilonx or https://www.avilonx.site
    'site_url' => 'http://localhost/avilonx',

    // Push notifications (optional): contact sent to push services.
    'push_contact' => 'mailto:admin@avilonx.site',

    // Forgot password emails (optional): Resend API key and sender.
    'resend_api_key' => '',
    'reset_from' => 'AvilonX <noreply@avilonx.site>',
];
