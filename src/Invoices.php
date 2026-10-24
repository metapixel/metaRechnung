<?php
/*
 * Rechnungs-App – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

final class Invoices
{
    public static function kleinunternehmer(): bool
    {
        return env('KLEINUNTERNEHMER', '0') === '1';
    }

    public static function num(mixed $s): float
    {
        return (float)str_replace(',', '.', (string)$s);
    }

    /** Strenge Zahlenprüfung für Formularwerte (Komma oder Punkt; keine Exponentialschreibweise, kein Text). */
    public static function numStrict(mixed $s, string $label, int $pos, float $min, float $max): float
    {
        $s = is_string($s) ? trim(str_replace(',', '.', $s)) : '';
        if (!preg_match('/^-?\d{1,9}(\.\d{1,4})?$/', $s)) {
            throw new \RuntimeException("Position $pos: $label ist keine gültige Zahl.");
        }
        $v = (float)$s;
        if ($v < $min || $v > $max) {
            throw new \RuntimeException("Position $pos: $label liegt außerhalb des zulässigen Bereichs.");
        }
        return $v;
    }

    /** Summen je Steuersatz, auf Cent gerundet (Rundung pro Position und pro Steuersatz). */
    public static function totals(array $items, ?bool $kl = null): array
    {
        $kl ??= self::kleinunternehmer();
        $net = 0.0;
        $groups = [];
        foreach ($items as $i) {
            $line = round((float)$i['quantity'] * (float)$i['unit_price'], 2);
            $rate = $kl ? '0' : (string)(float)$i['vat_rate'];
            $groups[$rate] = ($groups[$rate] ?? 0.0) + $line;
            $net += $line;
        }
        $tax = 0.0;
        $taxes = [];
        foreach ($groups as $rate => $basis) {
            $t = round($basis * (float)$rate / 100, 2);
            $taxes[] = ['rate' => (float)$rate, 'basis' => round($basis, 2), 'tax' => $t];
            $tax += $t;
        }
        return ['net' => round($net, 2), 'taxes' => $taxes, 'tax' => round($tax, 2), 'gross' => round($net + $tax, 2)];
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare(
            'SELECT i.*, c.name AS c_name, c.contact AS c_contact, c.email AS c_email, c.street AS c_street,
                    c.zip AS c_zip, c.city AS c_city, c.country AS c_country, c.vat_id AS c_vat_id,
                    c.buyer_reference AS c_buyer_ref
             FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?'
        );
        $st->execute([$id]);
        $inv = $st->fetch();
        if (!$inv) {
            return null;
        }
        $it = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY pos');
        $it->execute([$id]);
        $inv['items'] = $it->fetchAll();
        // Ausgestellte Rechnungen bleiben auf dem Stand zum Zeitpunkt der Ausstellung (Snapshot)
        $snap = $inv['snapshot'] ? json_decode((string)$inv['snapshot'], true) : null;
        $inv['co'] = $snap['company'] ?? company();
        $inv['kl'] = (bool)($snap['kl'] ?? self::kleinunternehmer());
        $inv['totals'] = self::totals($inv['items'], $inv['kl']);
        return $inv;
    }

    public static function findByToken(string $token): ?array
    {
        $st = db()->prepare("SELECT id FROM invoices WHERE public_token = ? AND status <> 'draft'");
        $st->execute([$token]);
        $id = $st->fetchColumn();
        return $id ? self::find((int)$id) : null;
    }

    public static function statusUrl(array $inv): string
    {
        return app_url() . 'status.php?t=' . $inv['public_token'];
    }

    /** Legt eine Kopie als neuen Entwurf an (Kunde, Positionen, Hinweistext). */
    public static function duplicate(int $id): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT customer_id, notes FROM invoices WHERE id = ?');
            $st->execute([$id]);
            $src = $st->fetch();
            if (!$src) {
                throw new \RuntimeException('Rechnung nicht gefunden.');
            }
            $due = date('Y-m-d', strtotime('+' . (int)env('PAYMENT_DAYS', '14') . ' days'));
            $pdo->prepare("INSERT INTO invoices (customer_id, status, issue_date, delivery_date, due_date, notes) VALUES (?, 'draft', CURDATE(), NULL, ?, ?)")
                ->execute([$src['customer_id'], $due, $src['notes']]);
            $new = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO invoice_items (invoice_id, pos, description, quantity, unit, unit_price, vat_rate)
                           SELECT ?, pos, description, quantity, unit, unit_price, vat_rate FROM invoice_items WHERE invoice_id = ?')
                ->execute([$new, $id]);
            $pdo->commit();
            return $new;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function listAll(): array
    {
        $rows = db()->query(
            'SELECT i.id, i.number, i.status, i.issue_date, i.due_date, i.sent_at, i.snapshot, c.name AS c_name
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             ORDER BY i.issue_date DESC, i.id DESC'
        )->fetchAll();
        $items = [];
        foreach (db()->query('SELECT invoice_id, quantity, unit_price, vat_rate FROM invoice_items') as $r) {
            $items[$r['invoice_id']][] = $r;
        }
        foreach ($rows as &$r) {
            $snap = $r['snapshot'] ? json_decode((string)$r['snapshot'], true) : null;
            $r['totals'] = self::totals($items[$r['id']] ?? [], isset($snap['kl']) ? (bool)$snap['kl'] : null);
        }
        unset($r);
        return $rows;
    }

    public static function saveDraft(?int $id, array $in): int
    {
        $pdo = db();
        $date = static function (mixed $d, string $label): ?string {
            $d = is_string($d) ? trim($d) : '';
            if ($d === '') {
                return null;
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
                throw new \RuntimeException($label . ': ungültiges Datum.');
            }
            return $d;
        };
        $issue = $date($in['issue_date'] ?? '', 'Rechnungsdatum') ?: date('Y-m-d');
        $due = $date($in['due_date'] ?? '', 'Fälligkeitsdatum') ?: date('Y-m-d', strtotime($issue . ' +' . (int)env('PAYMENT_DAYS', '14') . ' days'));
        $delivery = $date($in['delivery_date'] ?? '', 'Leistungsdatum');
        $notes = clean($in['notes'] ?? '', true) ?: null;
        if (mb_strlen((string)$notes) > 5000) {
            throw new \RuntimeException('Die Notiz ist zu lang (höchstens 5000 Zeichen).');
        }
        $cs = db()->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
        $cs->execute([(int)($in['customer_id'] ?? 0)]);
        if (!(int)$cs->fetchColumn()) {
            throw new \RuntimeException('Der gewählte Kunde existiert nicht.');
        }

        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $pdo->prepare("INSERT INTO invoices (customer_id, status, issue_date, delivery_date, due_date, notes) VALUES (?, 'draft', ?, ?, ?, ?)")
                    ->execute([(int)$in['customer_id'], $issue, $delivery, $due, $notes]);
                $id = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare("UPDATE invoices SET customer_id = ?, issue_date = ?, delivery_date = ?, due_date = ?, notes = ? WHERE id = ? AND status = 'draft'")
                    ->execute([(int)$in['customer_id'], $issue, $delivery, $due, $notes, $id]);
                $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            }
            $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, pos, description, quantity, unit, unit_price, vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $pos = 0;
            foreach ((array)($in['desc'] ?? []) as $k => $desc) {
                $desc = clean(is_string($desc) ? $desc : '', true);
                if ($desc === '') {
                    continue;
                }
                if (mb_strlen($desc) > 500) {
                    throw new \RuntimeException('Position ' . ($pos + 1) . ': Die Beschreibung ist zu lang (höchstens 500 Zeichen).');
                }
                $n = ++$pos;
                $qty = self::numStrict($in['qty'][$k] ?? '1', 'Menge', $n, 0.001, 999999.0);
                $price = self::numStrict($in['price'][$k] ?? '0', 'Einzelpreis', $n, -9999999.99, 9999999.99);
                $vat = self::numStrict($in['vat'][$k] ?? '19', 'MwSt-Satz', $n, 0.0, 30.0);
                $unitIn = $in['unit'][$k] ?? '';
                $unit = is_string($unitIn) && array_key_exists($unitIn, UNITS) ? $unitIn : 'C62';
                $ins->execute([$id, $n, $desc, $qty, $unit, $price, $vat]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Vergibt die Rechnungsnummer, erzeugt das ZUGFeRD-PDF und legt es unveränderlich ab. */
    public static function issue(int $id): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT status, issue_date FROM invoices WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row || $row['status'] !== 'draft') {
                throw new \RuntimeException('Nur Entwürfe können ausgestellt werden.');
            }
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?');
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() === 0) {
                throw new \RuntimeException('Die Rechnung hat keine Positionen.');
            }

            $prefix = env('INVOICE_PREFIX', 'RE') . '-' . date('Y', strtotime($row['issue_date'])) . '-';
            $st = $pdo->prepare('SELECT MAX(CAST(SUBSTRING(number, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) FROM invoices WHERE number LIKE ? FOR UPDATE');
            $st->execute([$prefix . '%']);
            $number = $prefix . str_pad((string)((int)$st->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

            $snap = json_encode(['company' => company(), 'kl' => self::kleinunternehmer()], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("UPDATE invoices SET number = ?, status = 'issued', snapshot = ?, public_token = ? WHERE id = ?")
                ->execute([$number, $snap, bin2hex(random_bytes(16)), $id]);
            $inv = self::find($id);
            $pdf = Einvoice::pdf($inv);
            if (file_put_contents(self::pdfPath($number), $pdf) === false) {
                throw new \RuntimeException('PDF konnte nicht gespeichert werden (Schreibrechte auf storage/?).');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** NUR Testbetrieb: löscht Rechnungen (+ optional Kunden) und PDFs, Nummernkreis beginnt wieder bei 1. */
    public static function reset(bool $keepCustomers): array
    {
        $pdo = db();
        $inv = (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        $cus = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM invoice_items');
            $pdo->exec('DELETE FROM invoices');
            if (!$keepCustomers) {
                $pdo->exec('DELETE FROM customers');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        foreach (['invoice_items', 'invoices', 'customers'] as $t) {
            if ($t === 'customers' && $keepCustomers) {
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE ' . $t . ' AUTO_INCREMENT = 1');
            } catch (\PDOException $e) {
                // nicht kritisch
            }
        }
        $files = 0;
        foreach (glob(dirname(__DIR__) . '/storage/invoices/*.pdf') ?: [] as $f) {
            if (@unlink($f)) {
                $files++;
            }
        }
        foreach (glob(dirname(__DIR__) . '/storage/tmp/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        return ['invoices' => $inv, 'customers' => $keepCustomers ? 0 : $cus, 'files' => $files];
    }

    public static function pdfPath(string $number): string
    {
        return dirname(__DIR__) . '/storage/invoices/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $number) . '.pdf';
    }

    public static function pdfBytes(array $inv): string
    {
        $path = self::pdfPath($inv['number']);
        if (is_file($path)) {
            return (string)file_get_contents($path);
        }
        $pdf = Einvoice::pdf($inv);
        @file_put_contents($path, $pdf);
        return $pdf;
    }
}
