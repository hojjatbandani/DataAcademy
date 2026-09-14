<?php
/**
 * Data Academy — contact / enrolment form handler
 * Receives POST from index.html and ar/index.html, validates, e-mails the team
 * and appends a copy to storage/requests.log (so nothing is lost if mail() is not configured).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const RECIPIENT = 'team@thedatacademy.com';
const CC        = 'data.academy20@gmail.com';

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

$clean = static fn(string $key, int $max = 500): string =>
    mb_substr(trim(strip_tags((string)($_POST[$key] ?? ''))), 0, $max);

$name     = $clean('name', 120);
$email    = $clean('email', 160);
$phone    = $clean('phone', 40);
$org      = $clean('organization', 160);
$interest = $clean('interest', 120);
$message  = $clean('message', 3000);
$lang     = $clean('lang', 5) === 'ar' ? 'ar' : 'en';

if ($name === '' || $email === '' || $message === '') {
    fail('required');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('email');
}

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
$body = implode("\n", $lines);

// 1) Always persist a copy.
$dir = __DIR__ . '/storage';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/.htaccess', "Require all denied\n");
}
@file_put_contents($dir . '/requests.log', "==========\n" . $body . "\n\n", FILE_APPEND | LOCK_EX);

// 2) Try to e-mail the team (requires a configured mail transport in php.ini / sendmail).
$subject = '=?UTF-8?B?' . base64_encode('[Website] New enquiry from ' . $name) . '?=';
$headers = implode("\r\n", [
    'From: Data Academy Website <no-reply@thedatacademy.com>',
    'Reply-To: ' . $name . ' <' . $email . '>',
    'Cc: ' . CC,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
]);
$sent = @mail(RECIPIENT, $subject, $body, $headers);

// The request is stored either way, so report success to the visitor.
echo json_encode(['ok' => true, 'mailed' => (bool)$sent], JSON_UNESCAPED_UNICODE);
