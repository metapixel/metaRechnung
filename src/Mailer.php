<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
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

    /** @param array<int, array{path:string,name:string,mime:string}> $attachments zusätzliche, bereits geprüfte Anhänge (Attachments::collect) */
    public static function sendInvoice(array $inv, string $pdf, string $to, string $message, string $statusUrl, array $attachments = [], ?string $subject = null): void
    {
        $m = self::make();
        $m->addAddress($to);
        if (env('MAIL_BCC') !== '') {
            $m->addBCC(env('MAIL_BCC'));
        }
        if (!str_contains($message, $statusUrl)) {
            $message = rtrim($message) . "\n\nZahlungsstatus und Downloads (PDF, XRechnung):\n" . $statusUrl . "\n";
        }
        $subject = $subject !== null ? clean($subject) : '';
        $m->Subject = $subject !== '' ? $subject
            : (!empty($inv['cancels_id']) ? 'Stornorechnung ' . $inv['number'] . ' von ' . $inv['co']['name'] : Templates::fill(Templates::get('mail_invoice_subject'), Templates::vars($inv)));
        $m->Body = $message;
        $m->addStringAttachment($pdf, $inv['number'] . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');
        foreach ($attachments as $a) {
            $m->addAttachment($a['path'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime']);
        }
        $m->send();
    }

    /** Angebot per Mail (PDF im Anhang) */
    public static function sendOffer(array $offer, string $pdf, string $to, string $message, string $subject): void
    {
        $m = self::make();
        $m->addAddress($to);
        if (env('MAIL_BCC') !== '') {
            $m->addBCC(env('MAIL_BCC'));
        }
        $subject = clean($subject);
        $m->Subject = $subject !== '' ? $subject : Templates::fill(Templates::get('mail_offer_subject'), Templates::vars($offer, 'offer'));
        $m->Body = $message;
        $m->addStringAttachment($pdf, $offer['number'] . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');
        $m->send();
    }

    /** Zugangslink zu einem Export (ohne Anhang) */
    public static function sendExportLink(array $exp, string $to, string $message, string $url): void
    {
        $co = company();
        $m = self::make();
        $m->addAddress($to);
        if (env('MAIL_BCC') !== '') {
            $m->addBCC(env('MAIL_BCC'));
        }
        $msg = rtrim($message) . "\n\nDownload (" . $exp['label'] . ', ' . (int)$exp['invoice_count'] . " Belege):\n" . $url
            . "\nDer Link ist gültig bis " . date('d.m.Y', strtotime((string)$exp['expires_at'])) . ".\n";
        $m->Subject = 'Rechnungsexport ' . $exp['label'] . ' von ' . $co['name'];
        $m->Body = $msg;
        $m->send();
    }

    public static function sendTest(string $to): void
    {
        $m = self::make();
        $m->addAddress($to);
        $m->Subject = 'Testnachricht von metaRechnung';
        $m->Body = "Diese E-Mail bestätigt, dass der Mailversand von metaRechnung funktioniert.\n";
        $m->send();
    }
}
