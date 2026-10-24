<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/** Schema-Updates für bestehende Installationen (läuft automatisch, einmalig). */
final class Migrations
{
    public const VERSION = 3;

    public static function run(): void
    {
        $file = dirname(__DIR__) . '/storage/schema_version';
        $cur = is_file($file) ? (int)trim((string)file_get_contents($file)) : 1;
        if ($cur >= self::VERSION) {
            return;
        }
        $pdo = db();
        if ($cur < 2) {
            self::v2($pdo);
        }
        // v3: Artikelliste
        $pdo->exec("CREATE TABLE IF NOT EXISTS articles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            description VARCHAR(500) NOT NULL,
            unit VARCHAR(8) NOT NULL DEFAULT 'C62',
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            vat_rate DECIMAL(5,2) NOT NULL DEFAULT 19,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @file_put_contents($file, (string)self::VERSION);
    }

    private static function v2(\PDO $pdo): void
    {
        // v2: Käuferreferenz, Snapshot der Rechnungsstellerdaten, öffentliches Status-Token
        foreach ([
            'ALTER TABLE customers ADD COLUMN buyer_reference VARCHAR(100) NULL',
            'ALTER TABLE invoices ADD COLUMN snapshot MEDIUMTEXT NULL',
            'ALTER TABLE invoices ADD COLUMN public_token CHAR(32) NULL',
            'ALTER TABLE invoices ADD UNIQUE KEY uq_public_token (public_token)',
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                if (!in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061], true)) {
                    throw $e;
                }
            }
        }

        // bereits ausgestellte Rechnungen: aktuellen Stand als Snapshot einfrieren + Token vergeben
        $snap = json_encode(['company' => company(), 'kl' => Invoices::kleinunternehmer()], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("UPDATE invoices SET snapshot = ? WHERE status <> 'draft' AND snapshot IS NULL")->execute([$snap]);
        $upd = $pdo->prepare('UPDATE invoices SET public_token = ? WHERE id = ?');
        foreach ($pdo->query("SELECT id FROM invoices WHERE status <> 'draft' AND public_token IS NULL")->fetchAll() as $r) {
            $upd->execute([bin2hex(random_bytes(16)), $r['id']]);
        }

    }
}
