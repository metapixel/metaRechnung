<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

use Mpdf\Mpdf;

/**
 * Angebote: Entwurf → Angebot (Nummer + PDF, unveränderlich) → angenommen/abgelehnt → in Rechnung wandeln.
 * „Abgelaufen“ ist kein gespeicherter Status, sondern ergibt sich aus dem Gültigkeitsdatum.
 */
final class Offers
{
    public const STATUS = ['draft' => 'Entwurf', 'open' => 'offen', 'accepted' => 'angenommen', 'declined' => 'abgelehnt'];

    public static function find(int $id): ?array
    {
        $st = db()->prepare(
            'SELECT o.*, c.name AS c_name, c.contact AS c_contact, c.email AS c_email, c.street AS c_street,
                    c.zip AS c_zip, c.city AS c_city, c.country AS c_country, c.vat_id AS c_vat_id
             FROM offers o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?'
        );
        $st->execute([$id]);
        $o = $st->fetch();
        if (!$o) {
            return null;
        }
        $it = db()->prepare('SELECT * FROM offer_items WHERE offer_id = ? ORDER BY pos');
        $it->execute([$id]);
        $o['items'] = $it->fetchAll();
        // Ausgestellte Angebote bleiben auf dem Stand zum Zeitpunkt der Ausstellung (Snapshot)
        $snap = $o['snapshot'] ? json_decode((string)$o['snapshot'], true) : null;
        $o['co'] = $snap['company'] ?? company();
        $o['kl'] = (bool)($snap['kl'] ?? Invoices::kleinunternehmer());
        $o['design'] = $snap !== null ? PdfDesign::sanitize($snap['design'] ?? PdfDesign::legacy()) : PdfDesign::current();
        $o['tpl'] = $snap !== null && isset($snap['tpl']) ? $snap['tpl'] : Templates::pick(Templates::PDF_OFFER);
        $o['totals'] = Invoices::totals($o['items'], $o['kl']);
        $o['invoice'] = null;
        if (!empty($o['invoice_id'])) {
            $i = db()->prepare('SELECT id, number, status FROM invoices WHERE id = ?');
            $i->execute([(int)$o['invoice_id']]);
            $o['invoice'] = $i->fetch() ?: null;
        }
        $o['eff_status'] = self::effective($o);
        return $o;
    }

    /** Anzeigestatus: draft | open | expired | accepted | declined */
    public static function effective(array $o): string
    {
        if ($o['status'] === 'open' && $o['valid_until'] < date('Y-m-d')) {
            return 'expired';
        }
        return (string)$o['status'];
    }

    public static function label(string $eff): string
    {
        return self::STATUS[$eff] ?? ($eff === 'expired' ? 'abgelaufen' : $eff);
    }

    public static function listAll(): array
    {
        $rows = db()->query(
            'SELECT o.id, o.number, o.status, o.issue_date, o.valid_until, o.invoice_id, o.snapshot, c.name AS c_name
             FROM offers o JOIN customers c ON c.id = o.customer_id ORDER BY o.issue_date DESC, o.id DESC'
        )->fetchAll();
        $items = [];
        foreach (db()->query('SELECT offer_id, quantity, unit_price, vat_rate FROM offer_items') as $r) {
            $items[$r['offer_id']][] = $r;
        }
        foreach ($rows as &$r) {
            $snap = $r['snapshot'] ? json_decode((string)$r['snapshot'], true) : null;
            $r['totals'] = Invoices::totals($items[$r['id']] ?? [], isset($snap['kl']) ? (bool)$snap['kl'] : null);
            $r['eff_status'] = self::effective($r);
        }
        unset($r);
        return $rows;
    }

    private static function date(mixed $d, string $label): ?string
    {
        $d = is_string($d) ? trim($d) : '';
        if ($d === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
            throw new \RuntimeException($label . ': ungültiges Datum.');
        }
        return $d;
    }

    public static function saveDraft(?int $id, array $in): int
    {
        $pdo = db();
        $issue = self::date($in['issue_date'] ?? '', 'Angebotsdatum') ?: date('Y-m-d');
        $valid = self::date($in['valid_until'] ?? '', 'Gültig bis') ?: date('Y-m-d', strtotime($issue . ' +' . (int)env('OFFER_VALID_DAYS', '30') . ' days'));
        if ($valid < $issue) {
            throw new \RuntimeException('„Gültig bis“ liegt vor dem Angebotsdatum.');
        }
        $notes = clean($in['notes'] ?? '', true) ?: null;
        if (mb_strlen((string)$notes) > 5000) {
            throw new \RuntimeException('Die Notiz ist zu lang (höchstens 5000 Zeichen).');
        }
        $cs = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
        $cs->execute([(int)($in['customer_id'] ?? 0)]);
        if (!(int)$cs->fetchColumn()) {
            throw new \RuntimeException('Der gewählte Kunde existiert nicht.');
        }
        $items = Invoices::parseItems($in);

        $isNew = $id === null;
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $pdo->prepare("INSERT INTO offers (customer_id, status, issue_date, valid_until, notes) VALUES (?, 'draft', ?, ?, ?)")
                    ->execute([(int)$in['customer_id'], $issue, $valid, $notes]);
                $id = (int)$pdo->lastInsertId();
            } else {
                $u = $pdo->prepare("UPDATE offers SET customer_id = ?, issue_date = ?, valid_until = ?, notes = ? WHERE id = ? AND status = 'draft'");
                $u->execute([(int)$in['customer_id'], $issue, $valid, $notes, $id]);
                $chk = $pdo->prepare("SELECT COUNT(*) FROM offers WHERE id = ? AND status = 'draft'");
                $chk->execute([$id]);
                if (!(int)$chk->fetchColumn()) {
                    throw new \RuntimeException('Nur Entwürfe können bearbeitet werden.');
                }
                $pdo->prepare('DELETE FROM offer_items WHERE offer_id = ?')->execute([$id]);
            }
            $ins = $pdo->prepare('INSERT INTO offer_items (offer_id, pos, description, note, quantity, unit, unit_price, vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($items as $n => $r) {
                $ins->execute([$id, $n + 1, $r['description'], $r['note'], $r['quantity'], $r['unit'], $r['unit_price'], $r['vat_rate']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Audit::log($isNew ? 'offer.create' : 'offer.update', 'offer', $id, 'Entwurf');
        return $id;
    }

    /** Vergibt die Angebotsnummer, erzeugt das PDF und legt es unveränderlich ab. */
    public static function issue(int $id): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT status, issue_date, valid_until FROM offers WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row || $row['status'] !== 'draft') {
                throw new \RuntimeException('Nur Entwürfe können ausgestellt werden.');
            }
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM offer_items WHERE offer_id = ?');
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() === 0) {
                throw new \RuntimeException('Das Angebot hat keine Positionen.');
            }
            $prefix = env('OFFER_PREFIX', 'AN') . '-' . date('Y', strtotime($row['issue_date'])) . '-';
            $st = $pdo->prepare('SELECT MAX(CAST(SUBSTRING(number, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) FROM offers WHERE number LIKE ? FOR UPDATE');
            $st->execute([$prefix . '%']);
            $number = $prefix . str_pad((string)((int)$st->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

            $snap = json_encode([
                'company' => company(), 'kl' => Invoices::kleinunternehmer(), 'design' => PdfDesign::current(),
                'tpl' => Templates::pick(Templates::PDF_OFFER),
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("UPDATE offers SET number = ?, status = 'open', snapshot = ? WHERE id = ?")->execute([$number, $snap, $id]);
            $o = self::find($id);
            $pdf = self::pdf($o);
            $dir = dirname(self::pdfPath($number));
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            // Geprüft: Pfad wird serverseitig gebildet (fester Ordner, Nummer auf [A-Za-z0-9_-] beschränkt)
            // nosemgrep
            if (file_put_contents(self::pdfPath($number), $pdf) === false) {
                throw new \RuntimeException('PDF konnte nicht gespeichert werden (Schreibrechte auf storage/?).');
            }
            $pdo->commit();
            Audit::log('offer.issue', 'offer', $id, 'Angebot ' . $number);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Entscheidung des Kunden festhalten bzw. zurücknehmen (offen). */
    public static function setStatus(int $id, string $to): void
    {
        if (!in_array($to, ['open', 'accepted', 'declined'], true)) {
            throw new \RuntimeException('Ungültiger Status.');
        }
        $o = self::find($id);
        if (!$o || $o['status'] === 'draft') {
            throw new \RuntimeException('Nur ausgestellte Angebote haben einen Status.');
        }
        db()->prepare('UPDATE offers SET status = ?, decided_at = ? WHERE id = ?')
            ->execute([$to, $to === 'open' ? null : date('Y-m-d'), $id]);
        Audit::log('offer.' . ($to === 'open' ? 'reopen' : $to), 'offer', $id, (string)$o['number']);
    }

    /** Erzeugt aus dem Angebot einen Rechnungsentwurf (Kunde + Positionen) und markiert das Angebot als angenommen. */
    public static function convert(int $id): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, number, status, customer_id, issue_date, invoice_id FROM offers WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $o = $st->fetch();
            if (!$o || $o['status'] === 'draft') {
                throw new \RuntimeException('Nur ausgestellte Angebote können in eine Rechnung gewandelt werden.');
            }
            if ($o['status'] === 'declined') {
                throw new \RuntimeException('Das Angebot ist als abgelehnt markiert. Bitte zuerst wieder auf „offen“ setzen.');
            }
            if ($o['invoice_id'] !== null) {
                throw new \RuntimeException('Aus diesem Angebot wurde bereits eine Rechnung erstellt.');
            }
            $due = date('Y-m-d', strtotime('+' . (int)env('PAYMENT_DAYS', '14') . ' days'));
            $notes = 'Gemäß Angebot ' . $o['number'] . ' vom ' . fdate($o['issue_date']) . '.';
            $pdo->prepare("INSERT INTO invoices (customer_id, offer_id, status, issue_date, delivery_date, due_date, notes) VALUES (?, ?, 'draft', CURDATE(), NULL, ?, ?)")
                ->execute([$o['customer_id'], $id, $due, $notes]);
            $new = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO invoice_items (invoice_id, pos, description, note, quantity, unit, unit_price, vat_rate)
                           SELECT ?, pos, description, note, quantity, unit, unit_price, vat_rate FROM offer_items WHERE offer_id = ?')
                ->execute([$new, $id]);
            $pdo->prepare("UPDATE offers SET invoice_id = ?, status = 'accepted', decided_at = COALESCE(decided_at, CURDATE()) WHERE id = ?")->execute([$new, $id]);
            $pdo->commit();
            Audit::log('offer.convert', 'offer', $id, 'Angebot ' . $o['number'] . ' → Rechnungsentwurf #' . $new);
            return $new;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function duplicate(int $id): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT customer_id, notes FROM offers WHERE id = ?');
            $st->execute([$id]);
            $src = $st->fetch();
            if (!$src) {
                throw new \RuntimeException('Angebot nicht gefunden.');
            }
            $valid = date('Y-m-d', strtotime('+' . (int)env('OFFER_VALID_DAYS', '30') . ' days'));
            $pdo->prepare("INSERT INTO offers (customer_id, status, issue_date, valid_until, notes) VALUES (?, 'draft', CURDATE(), ?, ?)")
                ->execute([$src['customer_id'], $valid, $src['notes']]);
            $new = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO offer_items (offer_id, pos, description, note, quantity, unit, unit_price, vat_rate)
                           SELECT ?, pos, description, note, quantity, unit, unit_price, vat_rate FROM offer_items WHERE offer_id = ?')
                ->execute([$new, $id]);
            $pdo->commit();
            Audit::log('offer.duplicate', 'offer', $new, 'Kopie von Angebot #' . $id);
            return $new;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function pdfPath(string $number): string
    {
        return dirname(__DIR__) . '/storage/offers/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $number) . '.pdf';
    }

    public static function pdfBytes(array $o): string
    {
        $path = self::pdfPath((string)$o['number']);
        if (is_file($path)) {
            return (string)file_get_contents($path);
        }
        return self::pdf($o);
    }

    /** Einfaches PDF (kein ZUGFeRD – ein Angebot ist keine Rechnung). */
    public static function pdf(array $inv): string
    {
        $co = $inv['co'];
        $kl = $inv['kl'];
        $tmp = dirname(__DIR__) . '/storage/tmp';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $lh = Letterhead::info();
        $hideSender = $lh !== null && $lh['hideSender'];
        $hideFooter = $lh !== null && $lh['hideFooter'];
        $design = PdfDesign::sanitize($inv['design'] ?? PdfDesign::current());
        $inv['design'] = $design;

        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => $lh['ml'] ?? 20, 'margin_right' => $lh['mr'] ?? 20,
            'margin_top' => $lh['mt'] ?? 18, 'margin_bottom' => $lh['mb'] ?? 18,
            'tempDir' => $tmp,
            'default_font' => $design['font'], 'default_font_size' => PdfDesign::fontSize($design),
        ]);
        $mpdf->SetTitle('Angebot ' . $inv['number']);
        $mpdf->SetAuthor($co['name']);
        if ($design['pagenum']) {
            $mpdf->SetHTMLFooter('<div style="text-align:right; font-size:7.5pt; color:#555">Seite {PAGENO} von {nbpg}</div>');
        }
        if ($lh !== null) {
            if ($lh['type'] === 'pdf') {
                $mpdf->SetDocTemplate($lh['path'], true);
            } else {
                $mpdf->SetDefaultBodyCSS('background', "url('" . $lh['path'] . "')");
                $mpdf->SetDefaultBodyCSS('background-image-resize', 6);
            }
        }
        ob_start();
        require __DIR__ . '/views/offer_pdf.php';
        $html = (string)ob_get_clean();
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', 'S');
    }
}
