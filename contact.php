<?php
/**
 * Data Academy — contact / enrolment form handler
 * Receives POST from index.html and ar/index.html, validates, e-mails the team (SMTP or mail())
 * and appends a copy to requests.log so nothing is lost if delivery fails.
 * Settings live in config.php (+ config.local.php for the mailbox password).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$cfg = require __DIR__ . '/config.php';

function fail(string $error, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('method', 405);
}

// Honeypot: real users never fill this field.
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

/* ---------- Input ---------- */
$clean = static function (string $key, int $max = 500, bool $singleLine = true): string {
    $v = trim(strip_tags((string)($_POST[$key] ?? '')));
    if ($singleLine) {
        $v = preg_replace('/[\r\n\t]+/', ' ', $v); // never let user input reach a mail header
    }
    return mb_substr($v, 0, $max);
};

$name     = $clean('name', 120);
$email    = $clean('email', 160);
$phone    = $clean('phone', 40);
$org      = $clean('organization', 160);
$interest = $clean('interest', 120);
$message  = $clean('message', 3000, false);
$lang     = $clean('lang', 5) === 'ar' ? 'ar' : 'en';

if ($name === '' || $email === '' || $message === '') {
    fail('required');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('email');
}

/* ---------- Message ---------- */
$lines = [
    'Name:         ' . $name,
    'Email:        ' . $email,
    'Phone:        ' . ($phone ?: '-'),
    'Organization: ' . ($org ?: '-'),
    'Interest:     ' . ($interest ?: '-'),
    'Language:     ' . $lang,
    'Received:     ' . date('c'),
    'IP:           ' . ($_SERVER['REMOTE_ADDR'] ?? '-'),
    '',
    'Message:',
    $message,
];
$body    = implode("\n", $lines);
$subject = '[Website] New enquiry from ' . $name . ($interest ? ' — ' . $interest : '');

/* ---------- 1) Persist a copy ---------- */
$logged = store_request($cfg['log_dir'], $body);

/* ---------- 2) Deliver ---------- */
$sent = false;
if ($cfg['smtp_pass'] !== '') {
    $sent = smtp_send($cfg, $name, $email, $subject, $body);
} else {
    $sent = php_mail_send($cfg, $name, $email, $subject, $body);
}

if (!$sent && !$logged) {
    fail('send', 500);
}
echo json_encode(['ok' => true, 'mailed' => $sent], JSON_UNESCAPED_UNICODE);
exit;

/* ======================================================================
   Helpers
   ====================================================================== */

/** RFC 2047 encoded-word for non-ASCII header text. */
function hdr(string $text): string {
    return preg_match('/[^\x20-\x7E]/', $text)
        ? '=?UTF-8?B?' . base64_encode($text) . '?='
        : $text;
}

/** Full RFC 5322 header block + base64 body shared by both transports. */
function build_headers(array $cfg, string $name, string $email, string $subject, bool $includeToSubject): array {
    $h = [];
    if ($includeToSubject) {
        $h[] = 'To: ' . implode(', ', $cfg['recipients']);
        $h[] = 'Subject: ' . hdr($subject);
    }
    $h[] = 'From: ' . hdr($cfg['from_name']) . ' <' . $cfg['from_email'] . '>';
    $h[] = 'Reply-To: ' . hdr($name) . ' <' . $email . '>';
    $h[] = 'Date: ' . date('r');
    $h[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $cfg['from_email'])[1] ?? 'localhost') . '>';
    $h[] = 'MIME-Version: 1.0';
    $h[] = 'Content-Type: text/plain; charset=UTF-8';
    $h[] = 'Content-Transfer-Encoding: base64';
    $h[] = 'X-Mailer: Data Academy Website';
    return $h;
}

function php_mail_send(array $cfg, string $name, string $email, string $subject, string $body): bool {
    $headers = implode("\r\n", build_headers($cfg, $name, $email, $subject, false));
    $ok = @mail(
        implode(', ', $cfg['recipients']),
        hdr($subject),
        chunk_split(base64_encode($body), 76, "\r\n"),
        $headers,
        '-f' . $cfg['from_email']
    );
    if (!$ok) {
        error_log('contact.php: mail() returned false');
    }
    return $ok;
}

/** Minimal authenticated SMTP client (SSL or STARTTLS), no external dependencies. */
function smtp_send(array $cfg, string $name, string $email, string $subject, string $body): bool {
    $secure = $cfg['smtp_secure'];
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['smtp_host'] . ':' . (int)$cfg['smtp_port'];
    $ctx    = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp     = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("contact.php SMTP: connect failed ($errno) $errstr");
        return false;
    }
    stream_set_timeout($fp, 20);

    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (!isset($line[3]) || $line[3] !== '-') break; // last line of a multi-line reply
        }
        return $out;
    };
    $cmd = static function (string $command, array $expect) use ($fp, $read): void {
        fwrite($fp, $command . "\r\n");
        $reply = $read();
        $code  = (int)substr($reply, 0, 3);
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException(trim($reply) ?: 'no reply');
        }
    };

    try {
        $read(); // 220 greeting
        $ehlo = 'EHLO ' . (preg_replace('/[^A-Za-z0-9.-]/', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'localhost');
        $cmd($ehlo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $cmd($ehlo, [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($cfg['smtp_user']), [334]);
        $cmd(base64_encode($cfg['smtp_pass']), [235]);

        $cmd('MAIL FROM:<' . $cfg['from_email'] . '>', [250]);
        foreach ($cfg['recipients'] as $rcpt) {
            $cmd('RCPT TO:<' . $rcpt . '>', [250, 251]);
        }
        $cmd('DATA', [354]);

        $data = implode("\r\n", build_headers($cfg, $name, $email, $subject, true))
              . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        $data = preg_replace('/^\./m', '..', $data); // dot-stuffing
        $cmd(rtrim($data, "\r\n") . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        return true;
    } catch (Throwable $e) {
        error_log('contact.php SMTP: ' . $e->getMessage());
        return false;
    } finally {
        fclose($fp);
    }
}

/** Append the request to requests.log, preferring a directory the web server cannot serve. */
function store_request(string $configured, string $body): bool {
    $candidates = $configured !== ''
        ? [$configured]
        : [dirname(__DIR__) . '/academy-private', __DIR__ . '/storage'];

    foreach ($candidates as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            continue;
        }
        if (!is_writable($dir)) {
            continue;
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        $ok = @file_put_contents($dir . '/requests.log', "==========\n" . $body . "\n\n", FILE_APPEND | LOCK_EX);
        if ($ok !== false) {
            return true;
        }
    }
    error_log('contact.php: could not write requests.log');
    return false;
}
