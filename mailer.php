<?php
/**
 * Path: /mailer.php   (web root: /home/admin/domains/payroll.renocon.ca/public_html/mailer.php)
 *
 * Central email sender.
 *
 * MODE 1 (default): PHP mail() — no setup, and proven to work on this server
 *                   (your old daily-report cron used it successfully).
 * MODE 2: authenticated SMTP via PHPMailer — better inbox placement. Switch to this
 *         if mail() lands in spam. See the SMTP block at the bottom.
 *
 * Usage anywhere:  require_once 'mailer.php';  $r = send_mail($to, $subject, $html);
 *                  if (!$r['ok']) { ... $r['error'] ... }
 */

require_once __DIR__ . '/engine/staging.php';

// ---- CONFIG -----------------------------------------------------------------
define('MAIL_MODE',      'mail');                    // 'mail'  or  'smtp'
define('MAIL_FROM',      'noreply@renocon.ca');      // must be a REAL mailbox on this domain
define('MAIL_FROM_NAME', 'Renocon Scheduling');

// SMTP settings — only used when MAIL_MODE is 'smtp'
define('SMTP_HOST',   'mail.renocon.ca');
define('SMTP_PORT',   465);          // 465 = ssl, 587 = tls
define('SMTP_SECURE', 'ssl');
define('SMTP_USER',   'noreply@renocon.ca');
define('SMTP_PASS', 'PUT-MAILBOX-PASSWORD-HERE');
// -----------------------------------------------------------------------------

/**
 * Send one HTML email. Returns ['ok'=>bool, 'error'=>string].
 */
function send_mail(string $to, string $subject, string $html, string $to_name = '', array $attachments = []): array {
    if (payroll_staging_outbound_blocked('mail')) {
        return ['ok' => false, 'error' => 'Staging does not send mail.', 'blocked' => true];
    }
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'No valid email address on file.'];
    }

    if (MAIL_MODE === 'smtp') {
        return _send_via_smtp($to, $subject, $html, $to_name, $attachments);
    }

    // ---- PHP mail() (same mechanism your old daily-report script used) --------
    $from    = MAIL_FROM;
    $name    = MAIL_FROM_NAME;
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "From: {$name} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $body = _mail_body($html, $attachments, $headers);

    $ok = @mail($to, $subject, $body, $headers, "-f{$from}");   // -f sets the envelope sender

    return $ok
        ? ['ok' => true,  'error' => '']
        : ['ok' => false, 'error' => 'PHP mail() returned false (server refused to queue the message).'];
}

/**
 * @param list<array{filename:string,content:string,mime?:string}> $attachments
 */
function _mail_body(string $html, array $attachments, string &$headers): string
{
    $htmlBody = "<html><body>{$html}</body></html>";
    if ($attachments === []) {
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        return $htmlBody;
    }
    $boundary = 'bnd_' . bin2hex(random_bytes(12));
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $htmlBody . "\r\n";
    foreach ($attachments as $att) {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($att['filename'] ?? 'attachment.bin')) ?? 'attachment.bin';
        $mime = (string)($att['mime'] ?? 'application/octet-stream');
        if (!preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#', $mime)) {
            $mime = 'application/octet-stream';
        }
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mime}; name=\"{$filename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
        $body .= chunk_split(base64_encode((string)($att['content'] ?? ''))) . "\r\n";
    }
    $body .= "--{$boundary}--\r\n";
    return $body;
}

/**
 * SMTP path — only runs when MAIL_MODE === 'smtp'.
 * Requires /phpmailer/{PHPMailer.php, SMTP.php, Exception.php}
 */
function _send_via_smtp(string $to, string $subject, string $html, string $to_name, array $attachments = []): array {
    if (payroll_staging_outbound_blocked('mail')) {
        return ['ok' => false, 'error' => 'Staging does not send mail.', 'blocked' => true];
    }
    $dir = __DIR__ . '/phpmailer';
    if (!file_exists("$dir/PHPMailer.php")) {
        return ['ok' => false, 'error' => "SMTP mode is on but PHPMailer is not installed in $dir"];
    }
    require_once "$dir/Exception.php";
    require_once "$dir/PHPMailer.php";
    require_once "$dir/SMTP.php";

    $m = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host       = SMTP_HOST;
        $m->SMTPAuth   = true;
        $m->Username   = SMTP_USER;
        $m->Password   = SMTP_PASS;
        $m->SMTPSecure = SMTP_SECURE;
        $m->Port       = SMTP_PORT;
        $m->CharSet    = 'UTF-8';
        $m->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $m->addAddress($to, $to_name ?: $to);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body    = $html;
        $m->AltBody = strip_tags(str_replace(['<br>','</tr>','</p>'], "\n", $html));
        foreach ($attachments as $att) {
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($att['filename'] ?? 'attachment.bin')) ?? 'attachment.bin';
            $mime = (string)($att['mime'] ?? 'application/octet-stream');
            if (method_exists($m, 'addStringAttachment')) {
                $m->addStringAttachment((string)($att['content'] ?? ''), $filename, 'base64', $mime);
            }
        }
        $m->send();
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $m->ErrorInfo ?: $e->getMessage()];
    }
}
