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
    public const VERSION = 9;

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

        // v4: wiederkehrende Rechnungen + Token für cron.php
        $pdo->exec("CREATE TABLE IF NOT EXISTS recurring (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  frequency ENUM('month','quarter','year') NOT NULL DEFAULT 'month',
  period ENUM('previous','current','next') NOT NULL DEFAULT 'previous',
  mode ENUM('draft','issue','send') NOT NULL DEFAULT 'draft',
  next_run DATE NOT NULL,
  anchor_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
  end_date DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  mail_message TEXT NULL,
  last_run DATE NULL,
  last_invoice_id INT UNSIGNED NULL,
  run_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recurring_id INT UNSIGNED NOT NULL,
  pos SMALLINT UNSIGNED NOT NULL,
  description VARCHAR(500) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit VARCHAR(8) NOT NULL DEFAULT 'C62',
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 19,
  FOREIGN KEY (recurring_id) REFERENCES recurring(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (env('CRON_TOKEN') === '') {
            try {
                Config::save(['CRON_TOKEN' => bin2hex(random_bytes(20))]);
            } catch (\Throwable $e) {
                // config.php nicht beschreibbar: Token kann später in „Wiederkehrend“ erzeugt werden
            }
        }

        // v5: Notiz je Artikel/Position (Zusatztext unter der Beschreibung)
        foreach (['articles', 'invoice_items', 'recurring_items'] as $t) {
            try {
                $pdo->exec('ALTER TABLE ' . $t . ' ADD COLUMN note TEXT NULL AFTER description');
            } catch (\PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }

        // v6: Stornorechnung – verweist auf die stornierte Rechnung (höchstens eine Stornorechnung je Rechnung)
        foreach ([
            'ALTER TABLE invoices ADD COLUMN cancels_id INT UNSIGNED NULL AFTER customer_id',
            'ALTER TABLE invoices ADD UNIQUE KEY uq_cancels (cancels_id)',
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                if (!in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061], true)) {
                    throw $e;
                }
            }
        }

        // v7: Änderungsprotokoll (Hash-Kette)
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  actor VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  action VARCHAR(40) NOT NULL,
  entity VARCHAR(30) NOT NULL DEFAULT '',
  entity_id INT UNSIGNED NULL,
  summary VARCHAR(500) NOT NULL DEFAULT '',
  prev_hash CHAR(64) NOT NULL DEFAULT '',
  hash CHAR(64) NOT NULL,
  KEY idx_audit_time (created_at),
  KEY idx_audit_entity (entity, entity_id),
  KEY idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // v8: Exporte für den Steuerberater (ZIP mit Zugangslink)
        $pdo->exec("CREATE TABLE IF NOT EXISTS exports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token CHAR(32) NOT NULL UNIQUE,
  file VARCHAR(60) NOT NULL,
  label VARCHAR(100) NOT NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  invoice_count INT UNSIGNED NOT NULL DEFAULT 0,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  with_xml TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  downloads INT UNSIGNED NOT NULL DEFAULT 0,
  last_download DATETIME NULL,
  sent_to VARCHAR(190) NULL,
  sent_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // v9: Angebote (+ Positionen), Bezug Rechnung → Angebot, Zahlungserinnerungen
        $pdo->exec("CREATE TABLE IF NOT EXISTS offers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  status ENUM('draft','open','accepted','declined') NOT NULL DEFAULT 'draft',
  issue_date DATE NOT NULL,
  valid_until DATE NOT NULL,
  notes TEXT NULL,
  snapshot MEDIUMTEXT NULL,
  invoice_id INT UNSIGNED NULL,
  sent_at DATETIME NULL,
  decided_at DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS offer_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_id INT UNSIGNED NOT NULL,
  pos SMALLINT UNSIGNED NOT NULL,
  description VARCHAR(500) NOT NULL,
  note TEXT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit VARCHAR(8) NOT NULL DEFAULT 'C62',
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 19,
  FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'ALTER TABLE invoices ADD COLUMN offer_id INT UNSIGNED NULL AFTER cancels_id',
            'ALTER TABLE invoices ADD COLUMN reminded_at DATETIME NULL',
            'ALTER TABLE invoices ADD COLUMN reminder_count SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }

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
