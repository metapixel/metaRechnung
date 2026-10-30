<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Textvorlagen mit Platzhaltern: E-Mail-Texte (Rechnung, Angebot, Zahlungserinnerung, Export) und Texte auf dem PDF.
 * Gespeichert werden sie in config.php (Schlüssel TPL_…). Platzhalter in geschweiften Klammern, z. B. {nummer};
 * Groß-/Kleinschreibung ist egal, nicht zutreffende Werte bleiben leer, unbekannte Platzhalter werden beim Speichern abgelehnt.
 */
final class Templates
{
    /** Platzhalter => Beschreibung (für die Anzeige in den Einstellungen) */
    public const VARS = [
        'NUMMER' => 'Rechnungs- bzw. Angebotsnummer',
        'DATUM' => 'Rechnungs- bzw. Angebotsdatum',
        'FAELLIG' => 'Fälligkeitsdatum (nur Rechnung)',
        'BETRAG' => 'Bruttobetrag',
        'NETTO' => 'Nettobetrag',
        'KUNDE' => 'Name des Kunden',
        'ANSPRECHPARTNER' => 'Ansprechpartner des Kunden (beim Export: Steuerberater)',
        'ANREDE' => '„Guten Tag“ bzw. „Guten Tag Ansprechpartner“',
        'FIRMA' => 'Name deines Unternehmens',
        'ABSENDER' => 'Inhaber, sonst Firmenname (für die Grußformel)',
        'GUELTIG_BIS' => 'Gültigkeit des Angebots',
        'LINK' => 'Link zur Statusseite (nur Rechnung)',
        'ZEITRAUM' => 'Zeitraum (nur Export)',
        'ABLAUF' => 'Ablaufdatum des Links (nur Export)',
    ];

    /** Schlüssel => [Gruppe, Bezeichnung, einzeilig?, Standardtext] */
    public const DEFS = [
        'mail_invoice_subject' => ['mail', 'Rechnung – Betreff', true, 'Rechnung {nummer} von {firma}'],
        'mail_invoice_body' => ['mail', 'Rechnung – Text', false, "{anrede},\n\nanbei erhalten Sie die Rechnung {nummer} über {betrag}.\nBitte überweisen Sie den Betrag bis zum {faellig}.\n\nZahlungsstatus und Downloads (PDF, XRechnung):\n{link}\n\nMit freundlichen Grüßen\n{absender}"],
        'mail_offer_subject' => ['mail', 'Angebot – Betreff', true, 'Angebot {nummer} von {firma}'],
        'mail_offer_body' => ['mail', 'Angebot – Text', false, "{anrede},\n\nvielen Dank für Ihre Anfrage. Anbei erhalten Sie unser Angebot {nummer} über {betrag}.\nDas Angebot ist gültig bis zum {gueltig_bis}.\n\nBei Fragen melden Sie sich gerne.\n\nMit freundlichen Grüßen\n{absender}"],
        'mail_reminder_subject' => ['mail', 'Zahlungserinnerung – Betreff', true, 'Zahlungserinnerung zu Rechnung {nummer}'],
        'mail_reminder_body' => ['mail', 'Zahlungserinnerung – Text', false, "{anrede},\n\nzu unserer Rechnung {nummer} vom {datum} über {betrag} konnten wir bislang keinen Zahlungseingang feststellen. Sie war am {faellig} fällig.\n\nFalls sich Ihre Zahlung mit dieser Erinnerung überschnitten hat, betrachten Sie sie bitte als gegenstandslos. Andernfalls bitten wir um Überweisung des Betrags in den nächsten Tagen.\n\nRechnung und Zahlungsstatus:\n{link}\n\nMit freundlichen Grüßen\n{absender}"],
        'mail_export_body' => ['mail', 'Export für den Steuerberater – Text', false, "{anrede},\n\nanbei der Zugang zu den Ausgangsrechnungen von {firma}.\nEnthalten sind alle Rechnungen als PDF (ZUGFeRD), eine CSV-Übersicht (Rechnungsausgangsbuch) und – falls ausgewählt – XRechnung-XML sowie beigelegte Unterlagen (z. B. Kontoauszüge, Rechnungseingang).\n\nMit freundlichen Grüßen\n{absender}"],
        'pdf_invoice_intro' => ['pdf', 'Rechnung – Einleitung (über den Positionen)', false, ''],
        'pdf_invoice_payment' => ['pdf', 'Rechnung – Zahlungshinweis', false, 'Bitte überweisen Sie den Rechnungsbetrag bis zum {faellig} unter Angabe der Rechnungsnummer auf das unten genannte Konto.'],
        'pdf_offer_intro' => ['pdf', 'Angebot – Einleitung (über den Positionen)', false, 'Vielen Dank für Ihre Anfrage. Gerne unterbreiten wir Ihnen das folgende Angebot:'],
        'pdf_offer_closing' => ['pdf', 'Angebot – Schlusstext', false, 'Dieses Angebot ist gültig bis zum {gueltig_bis}.'],
    ];

    /** Welche Schlüssel am PDF hängen (werden beim Ausstellen eingefroren) */
    public const PDF_INVOICE = ['pdf_invoice_intro', 'pdf_invoice_payment'];
    public const PDF_OFFER = ['pdf_offer_intro', 'pdf_offer_closing'];

    public const MAX_SINGLE = 190;
    public const MAX_MULTI = 5000;

    public static function default(string $key): string
    {
        return self::DEFS[$key][3] ?? '';
    }

    public static function get(string $key): string
    {
        return env('TPL_' . strtoupper($key), self::default($key));
    }

    /** @param list<string> $keys @return array<string,string> */
    public static function pick(array $keys): array
    {
        $o = [];
        foreach ($keys as $k) {
            $o[$k] = self::get($k);
        }
        return $o;
    }

    /** PDF-Texte, wie sie vor Einführung der Vorlagen fest eingebaut waren (für ältere ausgestellte Rechnungen) */
    public static function legacyPdf(): array
    {
        return ['pdf_invoice_intro' => '', 'pdf_invoice_payment' => self::default('pdf_invoice_payment')];
    }

    /** Ersetzt {platzhalter} (egal ob groß/klein); bekannte, aber nicht belegte Werte werden leer, Unbekanntes bleibt stehen. */
    public static function fill(string $text, array $vars): string
    {
        return (string)preg_replace_callback('/\{([A-Za-z_]+)\}/', static function (array $m) use ($vars): string {
            $k = strtoupper($m[1]);
            if (array_key_exists($k, $vars)) {
                return (string)$vars[$k];
            }
            return array_key_exists($k, self::VARS) ? '' : $m[0];
        }, $text);
    }

    public static function render(string $key, array $vars): string
    {
        return self::fill(self::get($key), $vars);
    }

    /** @return list<string> unbekannte Platzhalter in $text */
    public static function unknown(string $text): array
    {
        preg_match_all('/\{([A-Za-z_]+)\}/', $text, $m);
        $bad = [];
        foreach ($m[1] as $name) {
            if (!array_key_exists(strtoupper($name), self::VARS)) {
                $bad[strtoupper($name)] ??= '{' . $name . '}';
            }
        }
        return array_values($bad);
    }

    /** Werte für Rechnung ($type 'invoice') oder Angebot ('offer'). Erwartet das Ergebnis von Invoices::find bzw. Offers::find. */
    public static function vars(array $doc, string $type = 'invoice'): array
    {
        $co = $doc['co'] ?? company();
        $contact = trim((string)($doc['c_contact'] ?? ''));
        $v = [
            'NUMMER' => (string)($doc['number'] ?? ''),
            'DATUM' => fdate($doc['issue_date'] ?? null),
            'BETRAG' => money((float)($doc['totals']['gross'] ?? 0)),
            'NETTO' => money((float)($doc['totals']['net'] ?? 0)),
            'KUNDE' => (string)($doc['c_name'] ?? ''),
            'ANSPRECHPARTNER' => $contact,
            'ANREDE' => 'Guten Tag' . ($contact !== '' ? ' ' . $contact : ''),
            'FIRMA' => (string)$co['name'],
            'ABSENDER' => (string)($co['owner'] ?: $co['name']),
            'FAELLIG' => '', 'GUELTIG_BIS' => '', 'LINK' => '', 'ZEITRAUM' => '', 'ABLAUF' => '',
        ];
        if ($type === 'invoice') {
            $v['FAELLIG'] = fdate($doc['due_date'] ?? null);
            if (!empty($doc['public_token'])) {
                $v['LINK'] = Invoices::statusUrl($doc);
            }
        } else {
            $v['GUELTIG_BIS'] = fdate($doc['valid_until'] ?? null);
        }
        return $v;
    }

    /**
     * Prüft Formulareingaben (Schlüssel => Text) und gibt bereinigte Werte zurück.
     * @return array{values: array<string,string>, errors: list<string>}
     */
    public static function validate(array $in): array
    {
        $values = [];
        $errors = [];
        foreach (self::DEFS as $key => [$group, $label, $single, $default]) {
            $raw = $in[$key] ?? null;
            if (!is_string($raw)) {
                $values[$key] = self::get($key);
                continue;
            }
            if ($single) {
                $raw = (string)preg_replace('/\s+/u', ' ', $raw);   // Zeilenumbrüche im Betreff werden zu Leerzeichen
            }
            $val = clean($raw, !$single);
            $max = $single ? self::MAX_SINGLE : self::MAX_MULTI;
            if (mb_strlen($val) > $max) {
                $errors[] = $label . ': zu lang (höchstens ' . $max . ' Zeichen).';
            }
            if ($val === '' && $default !== '') {
                $val = $default;   // leere Mail-/Zahlungstexte fallen auf den Standard zurück
            }
            if ($bad = self::unknown($val)) {
                $errors[] = $label . ': unbekannter Platzhalter ' . implode(', ', $bad) . '.';
            }
            $values[$key] = $val;
        }
        return ['values' => $values, 'errors' => $errors];
    }

    /** Speichert Vorlagen in config.php (Schlüssel TPL_…). */
    public static function save(array $values): void
    {
        $c = [];
        foreach ($values as $k => $v) {
            if (isset(self::DEFS[$k])) {
                $c['TPL_' . strtoupper($k)] = $v;
            }
        }
        Config::save($c);
    }

    public static function resetAll(): void
    {
        $c = [];
        foreach (self::DEFS as $k => $d) {
            $c['TPL_' . strtoupper($k)] = $d[3];
        }
        Config::save($c);
    }
}
