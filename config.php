<?php
/**
 * Data Academy — contact form settings
 *
 * Do NOT put the mailbox password in this file (it is committed to git and shipped in every build).
 * Create config.local.php next to it on the server (or locally before running build.js) containing:
 *
 *   <?php
 *   return ['smtp_pass' => 'the-mailbox-password'];
 *
 * Anything returned from config.local.php overrides the defaults below.
 */
declare(strict_types=1);

$config = [
    // Where enquiries are delivered. Add more addresses to the array if needed.
    'recipients' => ['team@t4id.com'],

    // Sender identity. Must be a mailbox on the site's own domain or the host will reject/spam-flag it.
    'from_email' => 'info@thedatacademy.com',
    'from_name'  => 'Data Academy Website',

    // SMTP (SiteGround): mail.<domain>, port 465 with SSL, or port 587 with STARTTLS ('tls').
    // Used only when smtp_pass is non-empty; otherwise PHP's mail() is used.
    'smtp_host'   => 'mail.thedatacademy.com',
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',            // 'ssl' | 'tls' | ''
    'smtp_user'   => 'info@thedatacademy.com',
    'smtp_pass'   => '',

    // Directory for requests.log. Empty = auto: a folder *above* the web root when writable
    // (never reachable from the browser), otherwise ./storage inside the site.
    'log_dir' => '',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_merge($config, $override);
    }
}

return $config;
