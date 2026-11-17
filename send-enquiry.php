<?php
/* Quarterre enquiry form handler (runs on the Krystal hosting).
   Emails each enquiry to the address set in the CMS: _data/content.yaml →
   enquiry.recipient_email. The recipient is only ever read from that file,
   never from the request, so the form can't be used to email anyone else. */

// Sending address: must be on the hosting account's own domain, which Krystal
// is authorised to send for (SPF/DKIM), so enquiries don't land in spam.
const SENDER_EMAIL = 'website@quarterre.k-hosting.co.uk';
const SENDER_NAME  = 'Quarterre website';
const MAX_PER_HOUR = 5; // per visitor IP, to stop floods

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply($ok, $message, $code) {
    http_response_code($code);
    echo json_encode(array('success' => $ok, 'message' => $message));
    exit;
}

// Reads `key:` from a top-level `section:` block of the CMS YAML file
function yaml_value($yaml, $section, $key) {
    if (!preg_match('/^' . $section . ':[ \t]*\R((?:[ \t]+.*(?:\R|$))+)/m', $yaml, $block)) return '';
    if (!preg_match('/^[ \t]+' . $key . ':[ \t]*(.*)$/m', $block[1], $value)) return '';
    return trim(trim($value[1]), "\"'");
}

function clean_line($s) {
    return trim(str_replace(array("\r", "\n"), ' ', $s));
}

function encode_header($s) {
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(false, 'Method not allowed', 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$get = function ($key, $max) use ($input) {
    $v = (isset($input[$key]) && is_string($input[$key])) ? trim($input[$key]) : '';
    return strlen($v) > $max ? false : $v;
};

$name    = $get('name', 200);
$email   = $get('email', 254);
$company = $get('company', 200);
$message = $get('message', 10000);
$honey   = $get('_honey', 1000);

// Bots fill the hidden field: pretend it worked, send nothing
if ($honey !== '') reply(true, 'Sent', 200);

if ($name === false || $email === false || $company === false || $message === false) {
    reply(false, 'Too long', 400);
}
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reply(false, 'Please fill in your name, a valid email and a message', 400);
}

$yaml = @file_get_contents(__DIR__ . '/_data/content.yaml');
$recipient = $yaml ? yaml_value($yaml, 'enquiry', 'recipient_email') : '';
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) reply(false, 'No valid recipient configured', 500);
$subjectBase = $yaml ? yaml_value($yaml, 'links', 'email_subject') : '';
if ($subjectBase === '') $subjectBase = 'Website enquiry';

// Simple rate limit per visitor IP
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$rateFile = sys_get_temp_dir() . '/quarterre_enquiry_' . md5($ip);
$recent = array();
$saved = @json_decode(@file_get_contents($rateFile), true);
if (is_array($saved)) {
    foreach ($saved as $t) {
        if (is_int($t) && $t > time() - 3600) $recent[] = $t;
    }
}
if (count($recent) >= MAX_PER_HOUR) reply(false, 'Too many enquiries, please try later', 429);

$name    = clean_line($name);
$company = clean_line($company);
$email   = clean_line($email);

$subject = encode_header(clean_line($subjectBase) . ' from ' . $name);
$body = "Name: $name\n"
      . "Email: $email\n"
      . 'Company: ' . ($company !== '' ? $company : '-') . "\n\n"
      . "Message:\n" . str_replace("\r\n", "\n", $message) . "\n\n"
      . "--\nSent from the enquiry form on quarterre.com. Reply to this email to answer $name directly.\n";

$headers = implode("\r\n", array(
    'From: ' . encode_header(SENDER_NAME) . ' <' . SENDER_EMAIL . '>',
    'Reply-To: ' . encode_header($name) . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
));

if (!mail($recipient, $subject, $body, $headers, '-f' . SENDER_EMAIL)) {
    reply(false, 'The server could not send the email', 500);
}

$recent[] = time();
@file_put_contents($rateFile, json_encode($recent));
reply(true, 'Sent', 200);
