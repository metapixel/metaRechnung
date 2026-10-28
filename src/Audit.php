<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Änderungsprotokoll: wer hat wann was getan. Die Einträge werden nur angehängt (keine Änderungs- oder Löschfunktion in der App)
 * und per Hash-Kette verknüpft: jeder Eintrag enthält den SHA-256-Hash des Vorgängers. Nachträgliche Änderungen oder Lücken
 * mitten in der Kette erkennt verify(). (Wer Datenbankzugriff hat, kann die Kette komplett neu rechnen oder am Ende kürzen –
 * das Protokoll ersetzt keine Zugriffsbeschränkung auf die Datenbank.)
 * Protokolliert werden keine Passwörter und keine Einstellungswerte, nur Feldnamen; Lesezugriffe werden nicht erfasst.
 */
final class Audit
{
    /** Anzeigenamen der Aktionen */
    public const LABELS = [
        'auth.login' => 'Angemeldet', 'auth.login_failed' => 'Anmeldung fehlgeschlagen', 'auth.logout' => 'Abgemeldet',
        'auth.account' => 'Zugangsdaten geändert', 'auth.password_failed' => 'Passwortänderung abgelehnt (falsches Passwort)',
        'invoice.create' => 'Rechnungsentwurf angelegt', 'invoice.update' => 'Rechnungsentwurf geändert', 'invoice.delete' => 'Entwurf gelöscht',
        'invoice.issue' => 'Rechnung ausgestellt', 'invoice.send' => 'Rechnung per E-Mail versendet', 'invoice.paid' => 'Als bezahlt markiert',
        'invoice.unpaid' => 'Wieder auf offen gesetzt', 'invoice.cancel' => 'Stornoentwurf angelegt', 'invoice.duplicate' => 'Rechnung dupliziert',
        'customer.create' => 'Kunde angelegt', 'customer.update' => 'Kunde geändert', 'customer.import' => 'Kunden importiert (CSV)',
        'article.create' => 'Artikel angelegt', 'article.update' => 'Artikel geändert', 'article.delete' => 'Artikel gelöscht', 'article.duplicate' => 'Artikel dupliziert',
        'recurring.create' => 'Wiederkehrende Vorlage angelegt', 'recurring.update' => 'Wiederkehrende Vorlage geändert', 'recurring.delete' => 'Wiederkehrende Vorlage gelöscht',
        'recurring.run' => 'Wiederkehrende Rechnung erzeugt', 'recurring.error' => 'Wiederkehrende Rechnung fehlgeschlagen', 'recurring.token' => 'Cron-Zugang geändert',
        'settings.save' => 'Einstellungen geändert', 'settings.letterhead' => 'Briefpapier geändert', 'settings.logo' => 'Logo geändert',
        'settings.testmail' => 'Testmail gesendet', 'settings.reset' => 'Testdaten zurückgesetzt',
        'design.css' => 'Eigenes CSS geändert', 'design.pdf' => 'PDF-Design geändert',
    ];

    /** Filtergruppen der Anzeige (Präfix vor dem Punkt) */
    public const GROUPS = [
        'invoice' => 'Rechnungen', 'customer' => 'Kunden', 'article' => 'Artikel', 'recurring' => 'Wiederkehrend',
        'settings' => 'Einstellungen', 'design' => 'Design', 'auth' => 'Anmeldung',
    ];

    private static ?string $actor = null;

    /** Setzt den Akteur fest (z. B. „cron“ für den Cronjob). */
    public static function setActor(?string $actor): void
    {
        self::$actor = $actor;
    }

    private static function squash(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        return mb_substr(trim($s), 0, $max);
    }

    private static function actor(): string
    {
        if (self::$actor !== null) {
            return self::$actor;
        }
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }
        return self::squash((string)($_SESSION['user'] ?? (!empty($_SESSION['auth']) ? env('APP_USER', 'admin') : '')), 64) ?: 'unbekannt';
    }

    private static function hash(string $prev, string $at, string $actor, string $ip, string $action, string $entity, ?int $id, string $summary): string
    {
        return hash('sha256', implode('|', [$prev, $at, $actor, $ip, $action, $entity, $id === null ? '' : (string)$id, $summary]));
    }

    /**
     * Schreibt einen Protokolleintrag. Stört nie den eigentlichen Vorgang: Fehler landen nur im Server-Log.
     * @param string|null $actor Akteur überschreiben (z. B. versuchter Benutzername bei fehlgeschlagener Anmeldung)
     */
    public static function log(string $action, string $entity = '', ?int $entityId = null, string $summary = '', ?string $actor = null): void
    {
        try {
            $pdo = db();
            $actor = self::squash($actor ?? self::actor(), 64);
            $summary = self::squash($summary, 500);
            $entity = self::squash($entity, 30);
            $ip = PHP_SAPI === 'cli' ? '' : self::squash(RateLimit::ip(), 45);
            $at = date('Y-m-d H:i:s');
            $own = !$pdo->inTransaction();
            if ($own) {
                $pdo->beginTransaction();
            }
            try {
                $prev = (string)$pdo->query('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
                $h = self::hash($prev, $at, $actor, $ip, $action, $entity, $entityId, $summary);
                $pdo->prepare('INSERT INTO audit_log (created_at, actor, ip, action, entity, entity_id, summary, prev_hash, hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$at, $actor, $ip, $action, $entity, $entityId, $summary, $prev, $h]);
                if ($own) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($own && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            error_log('metaRechnung audit: ' . $e->getMessage());
        }
    }

    /** Anzeigename einer Aktion (unbekannte Codes werden maskiert ausgegeben, nicht interpretiert). */
    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /**
     * Liest Einträge (neueste zuerst).
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public static function search(string $group, string $q, int $page, int $per = 100): array
    {
        $where = [];
        $args = [];
        if ($group !== '' && isset(self::GROUPS[$group])) {
            $where[] = 'action LIKE ?';
            $args[] = $group . '.%';
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(summary LIKE ? OR actor LIKE ? OR ip LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $c = db()->prepare('SELECT COUNT(*) FROM audit_log' . $w);
        $c->execute($args);
        $total = (int)$c->fetchColumn();
        $page = max(1, $page);
        $st = db()->prepare('SELECT id, created_at, actor, ip, action, entity, entity_id, summary FROM audit_log' . $w . ' ORDER BY id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /**
     * Prüft die Hash-Kette.
     * @return array{ok: bool, count: int, broken_id: ?int}
     */
    public static function verify(): array
    {
        $st = db()->query('SELECT id, created_at, actor, ip, action, entity, entity_id, summary, prev_hash, hash FROM audit_log ORDER BY id');
        $prev = '';
        $n = 0;
        foreach ($st as $r) {
            $n++;
            $id = $r['entity_id'] === null ? null : (int)$r['entity_id'];
            if (!hash_equals($prev, (string)$r['prev_hash']) || !hash_equals(self::hash($prev, (string)$r['created_at'], (string)$r['actor'], (string)$r['ip'], (string)$r['action'], (string)$r['entity'], $id, (string)$r['summary']), (string)$r['hash'])) {
                return ['ok' => false, 'count' => $n, 'broken_id' => (int)$r['id']];
            }
            $prev = (string)$r['hash'];
        }
        return ['ok' => true, 'count' => $n, 'broken_id' => null];
    }
}
