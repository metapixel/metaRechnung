<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/** Einfache Artikel-/Leistungsliste, aus der Rechnungspositionen per Klick eingefügt werden. */
final class Articles
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return db()->query('SELECT * FROM articles ORDER BY description, id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM articles WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Prüft Formularwerte und gibt bereinigte Spaltenwerte zurück (wirft RuntimeException bei Fehlern). */
    public static function validate(array $in): array
    {
        $desc = is_string($in['description'] ?? '') ? clean($in['description'] ?? '') : '';
        if ($desc === '') {
            throw new \RuntimeException('Bitte eine Bezeichnung angeben.');
        }
        if (mb_strlen($desc) > 500) {
            throw new \RuntimeException('Die Bezeichnung ist zu lang (höchstens 500 Zeichen).');
        }
        $unit = is_string($in['unit'] ?? '') ? (string)$in['unit'] : '';
        if (!array_key_exists($unit, UNITS)) {
            $unit = 'C62';
        }
        $price = Invoices::numStrict($in['unit_price'] ?? '0', 'Einzelpreis', 1, -9999999.99, 9999999.99);
        $vat = Invoices::numStrict($in['vat_rate'] ?? '19', 'MwSt-Satz', 1, 0.0, 30.0);
        $note = clean(is_string($in['note'] ?? '') ? (string)$in['note'] : '', true);
        if (mb_strlen($note) > 1000) {
            throw new \RuntimeException('Die Notiz ist zu lang (höchstens 1000 Zeichen).');
        }
        return ['description' => $desc, 'note' => $note !== '' ? $note : null, 'unit' => $unit, 'unit_price' => $price, 'vat_rate' => $vat];
    }

    public static function save(?int $id, array $v): int
    {
        if ($id) {
            db()->prepare('UPDATE articles SET description=:description, note=:note, unit=:unit, unit_price=:unit_price, vat_rate=:vat_rate WHERE id=:id')->execute($v + ['id' => $id]);
            return $id;
        }
        db()->prepare('INSERT INTO articles (description, note, unit, unit_price, vat_rate) VALUES (:description, :note, :unit, :unit_price, :vat_rate)')->execute($v);
        return (int)db()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        db()->prepare('DELETE FROM articles WHERE id = ?')->execute([$id]);
    }
}
