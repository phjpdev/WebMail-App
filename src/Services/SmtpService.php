<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

class SmtpService
{
    private string $lastError = '';

    public function sendTest(string $toEmail): bool
    {
        $config = config('mail');
        $from = $config['mailbox_email'];

        return $this->send([
            'to' => $toEmail,
            'subject' => 'D&J Webmail — SMTP test',
            'body' => "This is a test message from the D&J Webmail application.\n\nSent at: " . date('Y-m-d H:i:s'),
            'from' => $from,
            'from_name' => config('app')['name'],
        ]);
    }

    /**
     * @param array{to: string, subject: string, body: string, from?: string, from_name?: string, reply_to?: string} $options
     */
    public function send(array $options): bool
    {
        $config = config('mail');
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $config['smtp']['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['mailbox_email'];
            $mail->Password = $config['mailbox_password'];
            $mail->Port = $config['smtp']['port'];

            if ($config['smtp']['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['smtp']['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            if (!$config['smtp']['validate_cert']) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            $from = $options['from'] ?? $config['mailbox_email'];
            $fromName = $options['from_name'] ?? config('app')['name'];

            $mail->setFrom($from, $fromName);
            $mail->addAddress($options['to']);

            if (!empty($options['reply_to'])) {
                $mail->addReplyTo($options['reply_to']);
            }

            $mail->Subject = $options['subject'];
            $mail->Body = $options['body'];
            $mail->AltBody = $options['body'];
            $mail->isHTML(false);

            $mail->send();

            return true;
        } catch (MailerException $e) {
            $this->lastError = $mail->ErrorInfo ?: $e->getMessage();
            app_log('SMTP send failed: ' . $this->lastError);

            return false;
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }
}
