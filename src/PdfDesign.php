<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Gestaltung der Rechnungs-PDFs (Schrift, Größe, Akzentfarbe, Tabellenstil, Absenderposition, Zusatztexte).
 * Alle Werte sind Aufzählungen, Zahlen oder #rrggbb – das erzeugte CSS enthält nie freien Text.
 * Beim Ausstellen wird die Gestaltung im Snapshot der Rechnung eingefroren.
 */
final class PdfDesign
{
    /** Schriftname (mPDF) => Anzeigename */
    public const FONTS = [
        'dejavusanscondensed' => 'DejaVu Sans Condensed (schmal, platzsparend)',
        'dejavusans'          => 'DejaVu Sans (normal breit)',
        'dejavuserifcondensed' => 'DejaVu Serif Condensed (Serifenschrift)',
    ];
    public const SIZES = ['small' => 8.5, 'normal' => 9.5, 'large' => 10.5];
    public const SIZE_LABELS = ['small' => 'Klein (8,5 pt)', 'normal' => 'Normal (9,5 pt)', 'large' => 'Groß (10,5 pt)'];
    public const TABLES = [
        'lines'  => 'Linien (Kopflinie und feine Zeilenlinien)',
        'rules'  => 'Schlicht (nur Kopf- und Abschlusslinie)',
        'zebra'  => 'Zebra (abwechselnd getönte Zeilen)',
        'filled' => 'Farbiger Tabellenkopf',
    ];
    public const SENDERS = ['right' => 'Rechts oben', 'left' => 'Links oben'];
    public const MAX_CLOSING = 500;
    public const MAX_FOOTER = 300;

    public const TEMPLATES = [
        'classic' => ['label' => 'Klassisch', 'text' => 'Das bisherige Aussehen: schmale Schrift, graue Linien.',
            'v' => ['font' => 'dejavusanscondensed', 'size' => 'normal', 'accent' => '#333333', 'table' => 'lines', 'sender' => 'right', 'title_accent' => false]],
        'modern' => ['label' => 'Modern', 'text' => 'Normal breite Schrift, farbiger Tabellenkopf, farbiger Titel.',
            'v' => ['font' => 'dejavusans', 'size' => 'normal', 'accent' => '#1d4ed8', 'table' => 'filled', 'sender' => 'right', 'title_accent' => true]],
        'minimal' => ['label' => 'Minimal', 'text' => 'Sehr zurückhaltend: schwarze Linien, nur das Nötigste.',
            'v' => ['font' => 'dejavusanscondensed', 'size' => 'normal', 'accent' => '#111111', 'table' => 'rules', 'sender' => 'right', 'title_accent' => false]],
        'elegant' => ['label' => 'Elegant', 'text' => 'Serifenschrift, Zebra-Zeilen, dezenter Weinrot-Akzent.',
            'v' => ['font' => 'dejavuserifcondensed', 'size' => 'normal', 'accent' => '#7f1d1d', 'table' => 'zebra', 'sender' => 'left', 'title_accent' => true]],
    ];

    /** Das Aussehen vor Version 1.8.0 – wird für ältere Rechnungen ohne gespeicherte Gestaltung verwendet. */
    public static function legacy(): array
    {
        return self::TEMPLATES['classic']['v'] + ['addrline' => true, 'footer' => true, 'pagenum' => false, 'closing' => '', 'footer_text' => ''];
    }

    public static function defaults(): array
    {
        return self::legacy();
    }

    public static function template(string $key): ?array
    {
        return isset(self::TEMPLATES[$key]) ? self::TEMPLATES[$key]['v'] + self::defaults() : null;
    }

    /** Aktuell eingestellte Gestaltung (config.php), fehlerhafte Werte fallen einzeln auf den Standard zurück. */
    public static function current(): array
    {
        $raw = env('PDF_DESIGN');
        $arr = $raw !== '' ? json_decode($raw, true) : null;
        return self::sanitize(is_array($arr) ? $arr : []);
    }

    /** Tolerante Normalisierung (für Gespeichertes): ungültige Einzelwerte werden durch den Standard ersetzt. */
    public static function sanitize(array $in): array
    {
        $d = self::defaults();
        $out = $d;
        if (isset($in['font']) && is_string($in['font']) && isset(self::FONTS[$in['font']])) {
            $out['font'] = $in['font'];
        }
        if (isset($in['size']) && is_string($in['size']) && isset(self::SIZES[$in['size']])) {
            $out['size'] = $in['size'];
        }
        if (isset($in['accent']) && is_string($in['accent']) && preg_match('/^#[0-9a-fA-F]{6}$/D', $in['accent'])) {
            $out['accent'] = strtolower($in['accent']);
        }
        if (isset($in['table']) && is_string($in['table']) && isset(self::TABLES[$in['table']])) {
            $out['table'] = $in['table'];
        }
        if (isset($in['sender']) && is_string($in['sender']) && isset(self::SENDERS[$in['sender']])) {
            $out['sender'] = $in['sender'];
        }
        foreach (['title_accent', 'addrline', 'footer', 'pagenum'] as $k) {
            if (isset($in[$k])) {
                $out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $out['closing'] = isset($in['closing']) && is_string($in['closing']) ? self::text($in['closing'], self::MAX_CLOSING) : '';
        $out['footer_text'] = isset($in['footer_text']) && is_string($in['footer_text']) ? self::text($in['footer_text'], self::MAX_FOOTER) : '';
        return $out;
    }

    /**
     * Strenge Prüfung von Formulareingaben. Gibt [Gestaltung, Fehlerliste] zurück.
     * @return array{0: array, 1: list<string>}
     */
    public static function fromForm(array $p): array
    {
        $errors = [];
        $enum = function (string $k, array $allowed, string $label) use ($p, &$errors): ?string {
            $v = $p[$k] ?? null;
            if (!is_string($v) || !isset($allowed[$v])) {
                $errors[] = $label . ': ungültige Auswahl.';
                return null;
            }
            return $v;
        };
        $d = self::defaults();
        $d['font'] = $enum('font', self::FONTS, 'Schrift') ?? $d['font'];
        $d['size'] = $enum('size', self::SIZE_LABELS, 'Schriftgröße') ?? $d['size'];
        $d['table'] = $enum('table', self::TABLES, 'Tabellenstil') ?? $d['table'];
        $d['sender'] = $enum('sender', self::SENDERS, 'Absender') ?? $d['sender'];
        $acc = $p['accent'] ?? null;
        if (!is_string($acc) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $acc)) {
            $errors[] = 'Akzentfarbe: bitte eine Farbe im Format #rrggbb angeben.';
        } else {
            $d['accent'] = strtolower($acc);
        }
        foreach (['title_accent', 'addrline', 'footer', 'pagenum'] as $k) {
            if (isset($p[$k]) && !is_string($p[$k])) {
                $errors[] = 'Ungültige Eingabe.';
            }
            $d[$k] = isset($p[$k]) && $p[$k] === '1';
        }
        foreach (['closing' => [self::MAX_CLOSING, 'Schlusstext'], 'footer_text' => [self::MAX_FOOTER, 'Fußzeilentext']] as $k => [$max, $label]) {
            $v = $p[$k] ?? '';
            if (!is_string($v)) {
                $errors[] = $label . ': ungültige Eingabe.';
                continue;
            }
            if (mb_strlen(str_replace("\r", '', $v)) > $max) {
                $errors[] = $label . ' ist zu lang (höchstens ' . $max . ' Zeichen).';
            }
            $d[$k] = self::text($v, $max);
        }
        return [$d, $errors];
    }

    /** Text ohne Steuerzeichen (Zeilenumbrüche bleiben), auf Länge gekürzt. */
    public static function text(string $s, int $max): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        $s = (string)preg_replace("/\n{3,}/", "\n\n", $s);
        return mb_substr(trim($s), 0, $max);
    }

    public static function toJson(array $d): string
    {
        return (string)json_encode(self::sanitize($d), JSON_UNESCAPED_UNICODE);
    }

    public static function fontSize(array $d): float
    {
        return self::SIZES[$d['size']] ?? 9.5;
    }

    // ---------------------------------------------------------------- Farben

    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    public static function luminance(string $hex): float
    {
        $c = array_map(static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** Kontrastverhältnis (WCAG) zweier Farben. */
    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** Lesbare Textfarbe (weiß oder fast schwarz) auf der Akzentfarbe. */
    public static function onAccent(string $accent): string
    {
        return self::contrast($accent, '#ffffff') >= self::contrast($accent, '#111111') ? '#ffffff' : '#111111';
    }

    /** Dunkelt eine Farbe ab, bis sie auf Weiß mindestens den Kontrast $min erreicht. */
    public static function darkenToContrast(string $hex, float $min = 4.5): string
    {
        [$r, $g, $b] = self::rgb($hex);
        for ($i = 0; $i < 20 && self::contrast($hex, '#ffffff') < $min; $i++) {
            $r = (int)round($r * 0.9);
            $g = (int)round($g * 0.9);
            $b = (int)round($b * 0.9);
            $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
        }
        return $hex;
    }

    // ---------------------------------------------------------------- CSS

    /** CSS für mPDF; enthält ausschließlich geprüfte Werte. */
    public static function css(array $d): string
    {
        $d = self::sanitize($d);
        $s = self::fontSize($d);
        $f = $d['font'];
        $a = $d['accent'];
        $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        $css = "body { font-family: $f; font-size: {$fmt($s)}pt; color: #111; }\n"
            . "table { border-collapse: collapse; width: 100%; }\n"
            . ".r { text-align: right; }\n"
            . ".small { font-size: {$fmt($s - 2)}pt; color: #555; }\n"
            . "h1 { font-size: {$fmt($s + 5.5)}pt; margin: 22px 0 8px 0;" . ($d['title_accent'] ? " color: $a;" : '') . " }\n"
            . ".plain td { border: 0; padding: 1px 3px; }\n"
            . "table.items th { text-align: left; padding: 4px 3px; font-size: {$fmt($s - 1)}pt; }\n"
            . "table.items td { padding: 4px 3px; vertical-align: top; }\n";
        switch ($d['table']) {
            case 'rules':
                $css .= "table.items th { border-bottom: 1px solid $a; }\n"
                    . "table.items { border-bottom: 1px solid $a; }\n";
                break;
            case 'zebra':
                $css .= "table.items th { border-bottom: 1px solid $a; }\n"
                    . "table.items tr.z td { background-color: #f1f1f1; }\n"
                    . "table.items { border-bottom: 0.5px solid $a; }\n";
                break;
            case 'filled':
                $css .= 'table.items th { background-color: ' . $a . '; color: ' . self::onAccent($a) . "; }\n"
                    . "table.items td { border-bottom: 0.5px solid #ccc; }\n";
                break;
            default:   // lines
                $css .= "table.items th { border-bottom: 1px solid $a; }\n"
                    . "table.items td { border-bottom: 0.5px solid #ccc; }\n";
        }
        $css .= "table.plain td.sum { border-top: 1px solid $a; }\n";
        return $css;
    }

    // ---------------------------------------------------------------- Logo-Farbe

    /** Vorschlag für die Akzentfarbe aus dem Logo (häufigste kräftige Farbe, ggf. abgedunkelt für Lesbarkeit) oder null. */
    public static function suggestFromImage(string $file): ?string
    {
        if (!function_exists('imagecreatefromstring') || !is_file($file) || filesize($file) > 6 * 1024 * 1024) {
            return null;
        }
        $src = @imagecreatefromstring((string)file_get_contents($file));
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $n = 40;
        $img = imagecreatetruecolor($n, $n);
        imagealphablending($img, false);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $n, $n, $w, $h);
        $buckets = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $c = imagecolorat($img, $x, $y);
                if ((($c >> 24) & 0x7F) >= 64) {
                    continue;   // überwiegend transparent
                }
                $r = ($c >> 16) & 255;
                $g = ($c >> 8) & 255;
                $b = $c & 255;
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                if ($max > 235 && $min > 215) {
                    continue;   // fast weiß
                }
                $sat = $max === 0 ? 0 : ($max - $min) / $max;
                $key = ($r >> 5) . '-' . ($g >> 5) . '-' . ($b >> 5);
                $buckets[$key] ??= ['n' => 0, 'w' => 0.0, 'r' => 0, 'g' => 0, 'b' => 0];
                $buckets[$key]['n']++;
                $buckets[$key]['w'] += $sat > 0.25 ? 1.0 : 0.3;   // kräftige Farben bevorzugen
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }
        if (!$buckets) {
            return null;
        }
        uasort($buckets, static fn($p, $q) => $q['w'] <=> $p['w']);
        $top = reset($buckets);
        $hex = sprintf('#%02x%02x%02x', intdiv($top['r'], $top['n']), intdiv($top['g'], $top['n']), intdiv($top['b'], $top['n']));
        return self::darkenToContrast($hex);
    }
}
