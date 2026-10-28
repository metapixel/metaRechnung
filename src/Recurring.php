<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/** Wiederkehrende Rechnungen: Vorlagen, Platzhalter für den Abrechnungszeitraum und die Erzeugung per Cronjob. */
final class Recurring
{
    public const FREQ = ['month' => 'monatlich', 'quarter' => 'vierteljährlich', 'year' => 'jährlich'];
    public const MODES = [
        'draft' => 'Als Entwurf anlegen (zum Prüfen)',
        'issue' => 'Automatisch ausstellen (Rechnungsnummer + PDF)',
        'send' => 'Automatisch ausstellen und per E-Mail versenden',
    ];
    public const PERIODS = [
        'previous' => 'Zurückliegender Zeitraum (Abrechnung im Nachhinein)',
        'current' => 'Laufender Zeitraum',
        'next' => 'Kommender Zeitraum (Abrechnung im Voraus)',
    ];
    public const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    public const MAX_CATCHUP = 12;   // höchstens so viele versäumte Läufe je Vorlage und Cron-Aufruf nachholen

    /** Platzhalter in Positionen und Notiz (Beschreibung im Formular ebenfalls aufgeführt). */
    public const VARS = [
        'ZEITRAUM' => 'Abrechnungszeitraum als Text, z. B. „Oktober 2026“, „4. Quartal 2026“ oder „2026“',
        'ZEITRAUM_VON' => 'erster Tag des Zeitraums (TT.MM.JJJJ)',
        'ZEITRAUM_BIS' => 'letzter Tag des Zeitraums (TT.MM.JJJJ)',
        'MONAT' => 'Monatsname zu Beginn des Zeitraums, z. B. „Oktober“',
        'MONAT_KURZ' => 'Kurzform, z. B. „Okt.“',
        'MONAT_NR' => 'Monatsnummer, z. B. „10“',
        'QUARTAL' => 'Quartalsnummer (1–4) zu Beginn des Zeitraums',
        'JAHR' => 'Jahr zu Beginn des Zeitraums',
        'INTERVALL' => '„monatlich“, „vierteljährlich“ oder „jährlich“',
        'DATUM' => 'Rechnungsdatum (TT.MM.JJJJ)',
    ];
    /** Zusätzlich nur im Mailtext. */
    public const MAIL_VARS = ['NUMMER' => 'Rechnungsnummer', 'BETRAG' => 'Gesamtbetrag', 'FAELLIG' => 'Fälligkeitsdatum', 'FIRMA' => 'Ihr Firmenname', 'STATUSLINK' => 'Link zur Statusseite'];

    public const DEFAULT_MAIL = "Guten Tag,\n\nanbei erhalten Sie die Rechnung {NUMMER} für {ZEITRAUM} über {BETRAG}.\nBitte überweisen Sie den Betrag bis zum {FAELLIG}.\n\nMit freundlichen Grüßen\n{FIRMA}";

    // ------------------------------------------------------------------ Datenzugriff

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return db()->query('SELECT r.*, c.name AS c_name, c.email AS c_email,
                (SELECT COUNT(*) FROM recurring_items i WHERE i.recurring_id = r.id) AS item_count
            FROM recurring r JOIN customers c ON c.id = r.customer_id ORDER BY r.active DESC, r.next_run, r.id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM recurring WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $it = db()->prepare('SELECT * FROM recurring_items WHERE recurring_id = ? ORDER BY pos');
        $it->execute([$id]);
        $r['items'] = $it->fetchAll();
        return $r;
    }

    public static function delete(int $id): void
    {
        db()->prepare('DELETE FROM recurring WHERE id = ?')->execute([$id]);
    }

    public static function setActive(int $id, bool $on): void
    {
        db()->prepare('UPDATE recurring SET active = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    }

    // ------------------------------------------------------------------ Zeitrechnung & Platzhalter

    public static function addMonths(\DateTimeImmutable $d, int $months, int $anchorDay): \DateTimeImmutable
    {
        $first = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($anchorDay, (int)$first->format('t')));
    }

    public static function nextRun(\DateTimeImmutable $from, string $freq, int $anchorDay): \DateTimeImmutable
    {
        return self::addMonths($from, ['month' => 1, 'quarter' => 3, 'year' => 12][$freq] ?? 1, $anchorDay);
    }

    /** @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} erster und letzter Tag des Abrechnungszeitraums */
    public static function period(\DateTimeImmutable $run, string $freq, string $which): array
    {
        $shift = ['previous' => -1, 'current' => 0, 'next' => 1][$which] ?? 0;
        $y = (int)$run->format('Y');
        $m = (int)$run->format('n');
        if ($freq === 'year') {
            $start = $run->setDate($y + $shift, 1, 1);
            $end = $start->setDate($y + $shift, 12, 31);
        } else {
            $len = $freq === 'quarter' ? 3 : 1;
            $m0 = $freq === 'quarter' ? intdiv($m - 1, 3) * 3 + 1 : $m;
            $start = $run->setDate($y, $m0, 1)->modify(($shift * $len >= 0 ? '+' : '') . ($shift * $len) . ' months');
            $end = $start->modify('+' . ($len - 1) . ' months')->modify('last day of this month');
        }
        return [$start->setTime(0, 0), $end->setTime(0, 0)];
    }

    /** @return array<string,string> */
    public static function vars(\DateTimeImmutable $run, string $freq, string $which, \DateTimeImmutable $issue): array
    {
        [$a, $b] = self::period($run, $freq, $which);
        $mi = (int)$a->format('n');
        $q = intdiv($mi - 1, 3) + 1;
        $y = $a->format('Y');
        $text = match ($freq) {
            'quarter' => $q . '. Quartal ' . $y,
            'year' => $y,
            default => self::MONTHS[$mi - 1] . ' ' . $y,
        };
        return [
            'ZEITRAUM' => $text,
            'ZEITRAUM_VON' => $a->format('d.m.Y'),
            'ZEITRAUM_BIS' => $b->format('d.m.Y'),
            'MONAT' => self::MONTHS[$mi - 1],
            'MONAT_KURZ' => mb_strlen(self::MONTHS[$mi - 1]) > 4 ? mb_substr(self::MONTHS[$mi - 1], 0, 3) . '.' : self::MONTHS[$mi - 1],
            'MONAT_NR' => str_pad((string)$mi, 2, '0', STR_PAD_LEFT),
            'QUARTAL' => (string)$q,
            'JAHR' => $y,
            'INTERVALL' => self::FREQ[$freq] ?? '',
            'DATUM' => $issue->format('d.m.Y'),
        ];
    }

    /** Ersetzt {NAME} (Groß-/Kleinschreibung egal). Unbekannte Platzhalter bleiben unverändert stehen. */
    public static function render(string $text, array $vars): string
    {
        return (string)preg_replace_callback('/\{([A-Za-z_]+)\}/', static function (array $m) use ($vars): string {
            $k = strtoupper($m[1]);
            return array_key_exists($k, $vars) ? (string)$vars[$k] : $m[0];
        }, $text);
    }

    /** @return string[] unbekannte Platzhalter im Text */
    public static function unknownVars(string $text, array $allowed): array
    {
        preg_match_all('/\{([A-Za-z_]+)\}/', $text, $m);
        return array_values(array_unique(array_filter($m[0], static fn($t) => !in_array(strtoupper(trim($t, '{}')), $allowed, true))));
    }

    // ------------------------------------------------------------------ Speichern

    private static function date(mixed $d, string $label): string
    {
        $d = is_string($d) ? trim($d) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
            throw new \RuntimeException($label . ': ungültiges Datum.');
        }
        return $d;
    }

    /** @return int ID der Vorlage */
    public static function save(?int $id, array $in): int
    {
        $name = clean(is_string($in['name'] ?? '') ? (string)$in['name'] : '');
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \RuntimeException('Bitte eine Bezeichnung (höchstens 120 Zeichen) angeben.');
        }
        $cid = (int)($in['customer_id'] ?? 0);
        $cs = db()->prepare('SELECT email FROM customers WHERE id = ?');
        $cs->execute([$cid]);
        $cust = $cs->fetch();
        if (!$cust) {
            throw new \RuntimeException('Der gewählte Kunde existiert nicht.');
        }
        $freq = (string)($in['frequency'] ?? '');
        $which = (string)($in['period'] ?? '');
        $mode = (string)($in['mode'] ?? '');
        if (!isset(self::FREQ[$freq]) || !isset(self::PERIODS[$which]) || !isset(self::MODES[$mode])) {
            throw new \RuntimeException('Intervall, Abrechnungszeitraum oder Ablauf ist ungültig.');
        }
        $old = $id !== null ? self::find($id) : null;
        if ($id !== null && !$old) {
            throw new \RuntimeException('Vorlage nicht gefunden.');
        }
        $next = self::date($in['next_run'] ?? '', 'Nächste Erstellung');
        if ((!$old || $old['next_run'] !== $next) && $next < date('Y-m-d')) {
            throw new \RuntimeException('Das Datum der nächsten Erstellung darf nicht in der Vergangenheit liegen.');
        }
        $end = trim((string)($in['end_date'] ?? '')) === '' ? null : self::date($in['end_date'], 'Enddatum');
        if ($end !== null && $end < $next) {
            throw new \RuntimeException('Das Enddatum liegt vor der nächsten Erstellung.');
        }
        $notes = clean(is_string($in['notes'] ?? '') ? (string)$in['notes'] : '', true) ?: null;
        $mail = clean(is_string($in['mail_message'] ?? '') ? (string)$in['mail_message'] : '', true) ?: null;
        if (mb_strlen((string)$notes) > 5000 || mb_strlen((string)$mail) > 5000) {
            throw new \RuntimeException('Notiz bzw. E-Mail-Text sind zu lang (höchstens 5000 Zeichen).');
        }
        if ($mode === 'send' && !filter_var((string)($cust['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Für den automatischen Versand braucht der Kunde eine gültige E-Mail-Adresse.');
        }
        $items = Invoices::parseItems($in);
        if (!$items) {
            throw new \RuntimeException('Bitte mindestens eine Position angeben.');
        }
        if (count($items) > 50) {
            throw new \RuntimeException('Höchstens 50 Positionen je Vorlage.');
        }
        $allowed = array_keys(self::VARS);
        $bad = [];
        foreach ($items as $it) {
            $bad = array_merge($bad, self::unknownVars($it['description'], $allowed), self::unknownVars((string)$it['note'], $allowed));
        }
        $bad = array_merge($bad, self::unknownVars((string)$notes, $allowed), self::unknownVars((string)$mail, array_merge($allowed, array_keys(self::MAIL_VARS))));
        if ($bad) {
            throw new \RuntimeException('Unbekannte Platzhalter: ' . implode(' ', array_unique($bad)) . ' – erlaubt sind ' . implode(' ', array_map(static fn($k) => '{' . $k . '}', $allowed)) . '.');
        }
        $anchor = $old && $old['next_run'] === $next ? (int)$old['anchor_day'] : (int)substr($next, 8, 2);
        $active = isset($in['active']) ? 1 : 0;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $pdo->prepare('INSERT INTO recurring (name, customer_id, frequency, period, mode, next_run, anchor_day, end_date, active, notes, mail_message)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$name, $cid, $freq, $which, $mode, $next, $anchor, $end, $active, $notes, $mail]);
                $id = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE recurring SET name = ?, customer_id = ?, frequency = ?, period = ?, mode = ?, next_run = ?, anchor_day = ?, end_date = ?, active = ?, notes = ?, mail_message = ? WHERE id = ?')
                    ->execute([$name, $cid, $freq, $which, $mode, $next, $anchor, $end, $active, $notes, $mail, $id]);
                $pdo->prepare('DELETE FROM recurring_items WHERE recurring_id = ?')->execute([$id]);
            }
            $ins = $pdo->prepare('INSERT INTO recurring_items (recurring_id, pos, description, note, quantity, unit, unit_price, vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($items as $n => $r) {
                $ins->execute([$id, $n + 1, $r['description'], $r['note'], $r['quantity'], $r['unit'], $r['unit_price'], $r['vat_rate']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    // ------------------------------------------------------------------ Ausführung

    /** Vorschau der nächsten Rechnung (Platzhalter aufgelöst). @return array{vars:array,items:array,notes:?string,period:string} */
    public static function preview(array $r): array
    {
        $run = new \DateTimeImmutable($r['next_run']);
        $v = self::vars($run, $r['frequency'], $r['period'], new \DateTimeImmutable('today'));
        $items = [];
        foreach ($r['items'] as $it) {
            $items[] = ['description' => self::render($it['description'], $v), 'note' => $it['note'] !== null ? self::render($it['note'], $v) : null] + $it;
        }
        return ['vars' => $v, 'items' => $items, 'notes' => $r['notes'] !== null ? self::render($r['notes'], $v) : null, 'period' => $v['ZEITRAUM']];
    }

    /**
     * Erzeugt alle fälligen Rechnungen. Jede Vorlage wird in einer Transaktion mit Zeilensperre bearbeitet:
     * Rechnung anlegen und next_run vorrücken geschehen atomar, ein Zeitraum wird daher nie doppelt abgerechnet –
     * auch nicht bei gleichzeitigen Aufrufen. Ausstellen/Versand folgen danach; Fehler dabei landen in last_error.
     *
     * @return list<array{id:int,name:string,status:string,invoice_id:?int,message:string}>
     */
    public static function runDue(?int $onlyId = null, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $ids = db()->prepare('SELECT id FROM recurring WHERE active = 1 AND next_run <= ?' . ($onlyId !== null ? ' AND id = ?' : '') . ' ORDER BY next_run, id');
        $ids->execute($onlyId !== null ? [$today->format('Y-m-d'), $onlyId] : [$today->format('Y-m-d')]);
        $out = [];
        foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) as $rid) {
            for ($i = 0; $i < self::MAX_CATCHUP; $i++) {
                $res = self::runOne((int)$rid, $today);
                if ($res === null) {
                    break;
                }
                $out[] = $res;
                if ($res['status'] === 'fehler' && $res['invoice_id'] === null) {
                    break;
                }
            }
        }
        return $out;
    }

    private static function runOne(int $rid, \DateTimeImmutable $today): ?array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM recurring WHERE id = ? FOR UPDATE');
            $st->execute([$rid]);
            $r = $st->fetch();
            if (!$r || !(int)$r['active'] || $r['next_run'] > $today->format('Y-m-d') || ($r['end_date'] !== null && $r['next_run'] > $r['end_date'])) {
                $pdo->rollBack();
                return null;
            }
            $run = new \DateTimeImmutable($r['next_run']);
            $vars = self::vars($run, $r['frequency'], $r['period'], $today);
            $due = $today->modify('+' . max(0, (int)env('PAYMENT_DAYS', '14')) . ' days')->format('Y-m-d');
            $notes = $r['notes'] !== null ? self::render($r['notes'], $vars) : null;
            $pdo->prepare("INSERT INTO invoices (customer_id, status, issue_date, delivery_date, due_date, notes) VALUES (?, 'draft', ?, NULL, ?, ?)")
                ->execute([(int)$r['customer_id'], $today->format('Y-m-d'), $due, $notes]);
            $inv = (int)$pdo->lastInsertId();
            $its = $pdo->prepare('SELECT * FROM recurring_items WHERE recurring_id = ? ORDER BY pos');
            $its->execute([$rid]);
            $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, pos, description, note, quantity, unit, unit_price, vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($its->fetchAll() as $n => $it) {
                $ins->execute([$inv, $n + 1, mb_substr(self::render($it['description'], $vars), 0, 500), $it['note'] !== null ? mb_substr(self::render($it['note'], $vars), 0, 1000) : null, $it['quantity'], $it['unit'], $it['unit_price'], $it['vat_rate']]);
            }
            $next = self::nextRun($run, $r['frequency'], (int)$r['anchor_day']);
            $finished = $r['end_date'] !== null && $next->format('Y-m-d') > $r['end_date'];
            $pdo->prepare('UPDATE recurring SET next_run = ?, last_run = ?, last_invoice_id = ?, run_count = run_count + 1, last_error = NULL, active = ? WHERE id = ?')
                ->execute([$next->format('Y-m-d'), $today->format('Y-m-d'), $inv, $finished ? 0 : 1, $rid]);
            $pdo->commit();
            Audit::log('recurring.run', 'invoice', $inv, 'Entwurf aus Vorlage „' . $r['name'] . '“ (#' . $rid . ') angelegt');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = 'Erzeugen fehlgeschlagen: ' . $e->getMessage();
            $pdo->prepare('UPDATE recurring SET last_error = ? WHERE id = ?')->execute([mb_substr($msg, 0, 500), $rid]);
            Audit::log('recurring.error', 'recurring', $rid, mb_substr($msg, 0, 400));
            return ['id' => $rid, 'name' => (string)($r['name'] ?? ''), 'status' => 'fehler', 'invoice_id' => null, 'message' => $msg];
        }

        $status = 'entwurf';
        $message = 'Entwurf angelegt';
        try {
            if ($r['mode'] === 'issue' || $r['mode'] === 'send') {
                Invoices::issue($inv);
                $status = 'ausgestellt';
                $message = 'Rechnung ausgestellt';
                if ($r['mode'] === 'send') {
                    self::send($inv, $r, $vars);
                    $status = 'versendet';
                    $message = 'Rechnung ausgestellt und versendet';
                }
            }
        } catch (\Throwable $e) {
            $message = $message . ', aber: ' . $e->getMessage();
            Audit::log('recurring.error', 'invoice', $inv, mb_substr($message, 0, 400));
            $status = 'fehler';
            $pdo->prepare('UPDATE recurring SET last_error = ? WHERE id = ?')->execute([mb_substr($message, 0, 500), $rid]);
        }
        return ['id' => $rid, 'name' => (string)$r['name'], 'status' => $status, 'invoice_id' => $inv, 'message' => $message];
    }

    private static function send(int $invoiceId, array $r, array $vars): void
    {
        $inv = Invoices::find($invoiceId);
        if (!$inv || !$inv['number']) {
            throw new \RuntimeException('Rechnung nicht gefunden.');
        }
        $to = trim((string)$inv['c_email']);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Der Kunde hat keine gültige E-Mail-Adresse – Rechnung wurde nicht versendet.');
        }
        if (env('APP_URL') === '') {
            throw new \RuntimeException('APP_URL ist nicht gesetzt (Einstellungen) – ohne sie kann kein Statuslink in die Mail.');
        }
        $url = Invoices::statusUrl($inv);
        $vars += [
            'NUMMER' => (string)$inv['number'], 'BETRAG' => money((float)$inv['totals']['gross']), 'FAELLIG' => fdate($inv['due_date']),
            'FIRMA' => (string)($inv['co']['owner'] ?: $inv['co']['name']), 'STATUSLINK' => $url,
        ];
        $text = self::render((string)($r['mail_message'] ?: self::DEFAULT_MAIL), $vars);
        Mailer::sendInvoice($inv, Invoices::pdfBytes($inv), $to, $text, $url);
        db()->prepare('UPDATE invoices SET sent_at = NOW() WHERE id = ?')->execute([$invoiceId]);
        Audit::log('invoice.send', 'invoice', $invoiceId, $inv['number'] . ' an ' . $to . ' (wiederkehrend)');
    }
}
