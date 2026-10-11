<?php
require_once __DIR__ . '/vendor/autoload.php';

function sendBookingNotification($siteName, $siteEmail, $siteFromEmail, $replyToEmail, $replyToName, $subject, $body)
{
    $smtpHost = getenv('SMTP_HOST') ?: '';
    $smtpUsername = getenv('SMTP_USERNAME') ?: '';
    $smtpPassword = getenv('SMTP_PASSWORD') ?: '';
    $smtpPort = (int) (getenv('SMTP_PORT') ?: '587');
    $smtpSecure = getenv('SMTP_SECURE') ?: 'tls';

    if ($smtpHost === '') {
        $headers = [
            'From: ' . $siteFromEmail,
            'Reply-To: ' . $replyToEmail,
            'Content-Type: text/plain; charset=UTF-8',
        ];
        return @mail($siteEmail, $subject, $body, implode("\r\n", $headers));
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth = $smtpUsername !== '' || $smtpPassword !== '';
        $mail->Host = $smtpHost;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->Port = $smtpPort;
        $mail->SMTPSecure = $smtpSecure;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($siteFromEmail, $siteName);
        $mail->addAddress($siteEmail, $siteName);
        if (filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyToEmail, $replyToName);
        }
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;
        return $mail->send();
    } catch (Throwable $exception) {
        return false;
    }
}
