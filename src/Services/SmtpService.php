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
     * @param array{
     *   to: string,
     *   subject: string,
     *   body: string,
     *   from?: string,
     *   from_name?: string,
     *   reply_to?: string,
     *   html_body?: string,
     *   in_reply_to?: string,
     *   references?: string,
     *   cc?: string,
     *   bcc?: string
     * } $options
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

            $toAddresses = $this->splitAddresses($options['to']);
            if ($toAddresses === []) {
                $this->lastError = 'No valid recipient addresses.';

                return false;
            }

            foreach ($toAddresses as $to) {
                $mail->addAddress($to);
            }

            if (!empty($options['cc'])) {
                foreach ($this->splitAddresses($options['cc']) as $cc) {
                    $mail->addCC($cc);
                }
            }

            if (!empty($options['bcc'])) {
                foreach ($this->splitAddresses($options['bcc']) as $bcc) {
                    $mail->addBCC($bcc);
                }
            }

            if (!empty($options['reply_to'])) {
                $mail->addReplyTo($options['reply_to']);
            }

            if (!empty($options['in_reply_to'])) {
                $mail->addCustomHeader('In-Reply-To', $options['in_reply_to']);
            }

            if (!empty($options['references'])) {
                $mail->addCustomHeader('References', $options['references']);
            }

            $mail->Subject = $options['subject'];

            if (!empty($options['html_body'])) {
                $mail->isHTML(true);
                $mail->Body = $options['html_body'];
                $mail->AltBody = $options['body'];
            } else {
                $mail->isHTML(false);
                $mail->Body = $options['body'];
            }

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

    /**
     * @return list<string>
     */
    private function splitAddresses(string $addresses): array
    {
        $parts = preg_split('/[,;]+/', $addresses) ?: [];
        $parts = array_map('trim', $parts);

        return array_values(array_filter($parts, fn ($a) => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)));
    }
}
