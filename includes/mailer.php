<?php

/**
 * BreadBreak mailer.
 *
 * SMTP (Gmail app password, PHPMailer vendored under vendor/phpmailer) when
 * configured; otherwise every message is filed into storage/outbox/*.html —
 * so local development and the E2E suite can assert on "sent" emails without
 * credentials, and nothing ever leaves the machine by accident.
 */
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/app.php';

function emailOutboxDir(): string
{
    return dirname(__DIR__) . '/storage/outbox';
}

/**
 * Send an HTML email. Returns true when the message was handed to SMTP or
 * stored in the outbox; false + error_log entry on any failure (callers show
 * a friendly "couldn't send, try again" — they never crash the request).
 */
function sendEmail(string $to, string $subject, string $html, string $text = ''): bool
{
    $config = mailConfig();
    $fromAddress = trim((string) $config['from_address']);
    if ($fromAddress === '') {
        $fromAddress = trim((string) $config['smtp_username']);
    }
    $fromName = (string) ($config['from_name'] ?? 'BreadBreak');

    if (trim((string) $config['smtp_host']) === '' || $fromAddress === '') {
        // ── Outbox mode ──
        try {
            $dir = emailOutboxDir();
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('cannot create ' . $dir);
            }
            $stamp = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
            $safeTo = preg_replace('/[^a-z0-9@.]+/i', '_', $to) ?: 'recipient';
            $file = $dir . '/' . $stamp . '_' . $safeTo . '.html';
            $headers = 'To: ' . $to . "\nSubject: " . $subject . "\nDate: " . date('r') . "\nMode: outbox\n\n";
            if (file_put_contents($file, $headers . $html) === false) {
                throw new RuntimeException('write failed for ' . $file);
            }
            error_log(sprintf('mailer: outbox stored "%s" → %s', $subject, $file));
            return true;
        } catch (Throwable $exception) {
            error_log('mailer: outbox write failed — ' . $exception->getMessage());
            return false;
        }
    }

    // ── SMTP mode (PHPMailer, vendored — no composer on shared hosting) ──
    try {
        require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
        require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $config['smtp_host'];
        $mail->Port = (int) ($config['smtp_port'] ?: 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) $config['smtp_username'];
        $mail->Password = (string) $config['smtp_password'];
        $mail->SMTPSecure = strtolower((string) $config['smtp_encryption']) === 'ssl' ? 'ssl' : 'starttls';
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text !== '' ? $text : trim(strip_tags($html));
        $mail->send();
        return true;
    } catch (Throwable $exception) {
        error_log('mailer: SMTP send failed — ' . $exception->getMessage());
        return false;
    }
}

/**
 * Shared inline-styled email shell — small enough to survive in any inbox,
 * no external images or fonts (blocked by many providers anyway).
 */
function emailLayout(string $heading, string $intro, string $body, string $link, string $button, string $footer): string
{
    $safe = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html>
<html lang="en">
<body style="margin:0; padding:0; background:#f6efe9; font-family:Arial, Helvetica, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6efe9; padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="max-width:520px; width:100%; background:#fffdfb; border:1px solid #eadfd5; border-radius:14px; overflow:hidden;">
        <tr><td style="background:#7b523b; padding:18px 28px;">
          <span style="color:#ffffff; font-size:18px; font-weight:bold; letter-spacing:.5px;">BREAD&#8209;BREAK</span>
        </td></tr>
        <tr><td style="padding:30px 28px 8px;">
          <h1 style="margin:0 0 12px; font-size:21px; color:#3d2c22;">' . $safe($heading) . '</h1>
          <p style="margin:0 0 10px; font-size:14px; line-height:1.6; color:#5d4a3d;">' . $safe($intro) . '</p>
          <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#5d4a3d;">' . $safe($body) . '</p>
          <p style="margin:0 0 6px;">
            <a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block; background:#7b523b; color:#ffffff; text-decoration:none; font-size:14px; font-weight:bold; padding:13px 26px; border-radius:8px;">' . $safe($button) . '</a>
          </p>
          <p style="margin:14px 0 0; font-size:12px; line-height:1.6; color:#8a7566; word-break:break-all;">Button not working? Copy this link into your browser:<br>' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>
        </td></tr>
        <tr><td style="padding:16px 28px 26px; border-top:1px solid #f0e7df; margin-top:18px;">
          <p style="margin:14px 0 0; font-size:12px; line-height:1.6; color:#8a7566;">' . $safe($footer) . '</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
}

/** Verification email — link expires in 24 hours (single-use). */
function sendVerificationEmail(string $toEmail, string $firstName, string $link): bool
{
    $name = trim($firstName) !== '' ? trim($firstName) : 'there';
    $html = emailLayout(
        'Confirm your email',
        'Hi ' . $name . ',',
        'One last step — confirm this email address so we know it’s really you. The link works once and expires in 24 hours.',
        $link,
        'Confirm my email',
        'If you didn’t create a BreadBreak account, you can safely ignore this email — nothing will change.'
    );
    $text = "Confirm your email\n\nHi {$name},\n\nOpen this link to verify your address (it expires in 24 hours):\n{$link}\n\nIf you didn't create a BreadBreak account, ignore this message.";
    return sendEmail($toEmail, 'Confirm your email — BreadBreak', $html, $text);
}

/** Password-reset email — link expires in 60 minutes (single-use). */
function sendPasswordResetEmail(string $toEmail, string $firstName, string $link): bool
{
    $name = trim($firstName) !== '' ? trim($firstName) : 'there';
    $html = emailLayout(
        'Reset your password',
        'Hi ' . $name . ',',
        'We received a request to reset your BreadBreak password. The link works once and expires in 60 minutes.',
        $link,
        'Reset my password',
        'If you didn’t request this, you can ignore this email — your current password still works.'
    );
    $text = "Reset your password\n\nHi {$name},\n\nOpen this link to choose a new password (it expires in 60 minutes):\n{$link}\n\nIf you didn't request this, ignore the message — your current password still works.";
    return sendEmail($toEmail, 'Reset your password — BreadBreak', $html, $text);
}
