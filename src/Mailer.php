<?php
/*
 * Rechnungs-App – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    private static function make(): PHPMailer
    {
        $m = new PHPMailer(true);
        $m->CharSet = 'UTF-8';
        if (env('SMTP_HOST') !== '') {
            $m->isSMTP();
            $m->Host = env('SMTP_HOST');
            $m->Port = (int)env('SMTP_PORT', '587');
            $secure = env('SMTP_SECURE', 'tls');
            if ($secure === 'ssl') {
                $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $m->SMTPSecure = '';
                $m->SMTPAutoTLS = false;
            }
            if (env('SMTP_USER') !== '') {
                $m->SMTPAuth = true;
                $m->Username = env('SMTP_USER');
                $m->Password = env('SMTP_PASS');
            }
        } else {
            $m->isMail();   // Webspace-eigener Mailversand (PHP mail())
        }
        $co = company();
        $m->setFrom(env('MAIL_FROM', $co['email']), env('MAIL_FROM_NAME', $co['name']));
        if ($co['email'] !== '') {
            $m->addReplyTo($co['email'], $co['name']);
        }
        return $m;
    }

    public static function sendInvoice(array $inv, string $pdf, string $to, string $message, string $statusUrl): void
    {
        $m = self::make();
        $m->addAddress($to);
        if (env('MAIL_BCC') !== '') {
            $m->addBCC(env('MAIL_BCC'));
        }
        if (!str_contains($message, $statusUrl)) {
            $message = rtrim($message) . "\n\nZahlungsstatus und Downloads (PDF, XRechnung):\n" . $statusUrl . "\n";
        }
        $m->Subject = 'Rechnung ' . $inv['number'] . ' von ' . $inv['co']['name'];
        $m->Body = $message;
        $m->addStringAttachment($pdf, $inv['number'] . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');
        $m->send();
    }

    public static function sendTest(string $to): void
    {
        $m = self::make();
        $m->addAddress($to);
        $m->Subject = 'Testnachricht der Rechnungs-App';
        $m->Body = "Diese E-Mail bestätigt, dass der Mailversand der Rechnungs-App funktioniert.\n";
        $m->send();
    }
}
