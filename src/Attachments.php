<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Zusätzliche Dateianhänge für den Rechnungsversand (z. B. Korrekturabzüge).
 * Die Dateien werden nicht gespeichert, sondern direkt aus dem Upload-Verzeichnis an die Mail angehängt.
 * Typ wird am Inhalt geprüft (nicht am Namen oder am vom Browser gemeldeten Typ); nur eine feste Liste ist erlaubt.
 */
final class Attachments
{
    public const MAX_FILES = 5;
    public const MAX_FILE = 5 * 1024 * 1024;     // je Datei
    public const MAX_TOTAL = 10 * 1024 * 1024;   // insgesamt (viele Mailserver lehnen größere Mails ab)

    /** Erlaubte Endungen => [MIME-Typ, Prüfart] */
    private const TYPES = [
        'pdf'  => ['application/pdf', 'pdf'],
        'png'  => ['image/png', 'png'],
        'jpg'  => ['image/jpeg', 'jpg'],
        'jpeg' => ['image/jpeg', 'jpg'],
        'gif'  => ['image/gif', 'gif'],
        'webp' => ['image/webp', 'webp'],
        'zip'  => ['application/zip', 'zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'zip'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'zip'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'zip'],
        'txt'  => ['text/plain', 'text'],
        'csv'  => ['text/csv', 'text'],
    ];

    public static function allowedExtensions(): string
    {
        return strtoupper(implode(', ', array_keys(self::TYPES)));
    }

    /** Größte einzelne Datei, die der Server überhaupt annimmt (Byte). */
    public static function serverFileLimit(): int
    {
        $v = fn(string $k): int => self::bytes((string)ini_get($k));
        $lim = min(self::MAX_FILE, $v('upload_max_filesize') ?: PHP_INT_MAX);
        $post = $v('post_max_size');
        return $post > 0 ? min($lim, $post) : $lim;
    }

    private static function bytes(string $s): int
    {
        $s = trim($s);
        if ($s === '') {
            return 0;
        }
        $n = (int)$s;
        return match (strtolower(substr($s, -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    }

    /** Dateiname bereinigen: nur Name (kein Pfad), keine Steuer-/Sonderzeichen, nicht zu lang. */
    public static function safeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = (string)preg_replace('/[\/:*?"<>|%;,\'`$&\\\\]/u', '_', $name);
        $name = ltrim(trim($name), '.');
        if (mb_strlen($name) > 100) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 90) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    /**
     * @param mixed $files  $_FILES['attachments'] (Mehrfach-Upload)
     * @return array<int, array{path:string,name:string,mime:string,size:int}>
     */
    public static function collect(mixed $files, string $reservedName = ''): array
    {
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            return [];
        }
        $out = [];
        $total = 0;
        $used = $reservedName !== '' ? [strtolower($reservedName) => true] : [];
        foreach ($files['name'] as $i => $orig) {
            $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $label = self::safeName((string)$orig) ?: 'Datei ' . ($i + 1);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new \RuntimeException('„' . $label . '“ ist größer als das Upload-Limit des Servers (' . ini_get('upload_max_filesize') . ').');
            }
            if ($err !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Der Upload von „' . $label . '“ ist fehlgeschlagen (Fehlercode ' . $err . ').');
            }
            if (count($out) >= self::MAX_FILES) {
                throw new \RuntimeException('Es sind höchstens ' . self::MAX_FILES . ' Anhänge erlaubt.');
            }
            $tmp = (string)($files['tmp_name'][$i] ?? '');
            if (!is_uploaded_file($tmp)) {
                throw new \RuntimeException('Ungültiger Upload.');
            }
            $size = (int)filesize($tmp);
            if ($size <= 0) {
                throw new \RuntimeException('„' . $label . '“ ist leer.');
            }
            if ($size > self::MAX_FILE) {
                throw new \RuntimeException('„' . $label . '“ ist größer als ' . (self::MAX_FILE / 1048576) . ' MB.');
            }
            $total += $size;
            if ($total > self::MAX_TOTAL) {
                throw new \RuntimeException('Die Anhänge sind zusammen größer als ' . (self::MAX_TOTAL / 1048576) . ' MB.');
            }
            $name = self::safeName((string)$orig);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name === '' || !isset(self::TYPES[$ext])) {
                throw new \RuntimeException('„' . $label . '“: Dateityp nicht erlaubt. Erlaubt sind ' . self::allowedExtensions() . '.');
            }
            [$mime, $kind] = self::TYPES[$ext];
            if (!self::contentMatches($tmp, $kind)) {
                throw new \RuntimeException('„' . $label . '“: Der Inhalt passt nicht zur Endung .' . $ext . '.');
            }
            // doppelte Namen (auch gegen die Rechnungs-PDF) vermeiden
            $base = pathinfo($name, PATHINFO_FILENAME);
            $n = 1;
            while (isset($used[strtolower($name)])) {
                $name = $base . ' (' . ++$n . ').' . $ext;
            }
            $used[strtolower($name)] = true;
            $out[] = ['path' => $tmp, 'name' => $name, 'mime' => $mime, 'size' => $size];
        }
        return $out;
    }

    private static function contentMatches(string $path, string $kind): bool
    {
        $head = (string)file_get_contents($path, false, null, 0, 16);
        switch ($kind) {
            case 'pdf':
                return str_starts_with($head, '%PDF-');
            case 'png':
                return str_starts_with($head, "\x89PNG\r\n\x1a\n");
            case 'jpg':
                return str_starts_with($head, "\xFF\xD8\xFF");
            case 'gif':
                return str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a');
            case 'webp':
                return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';
            case 'zip':
                return str_starts_with($head, "PK\x03\x04");
            case 'text':
                $s = (string)file_get_contents($path);
                // Kein Binärinhalt und kein aktiver Inhalt unter Textendung
                return !str_contains($s, "\0") && preg_match('//u', $s) === 1 && !preg_match('/<\?php|<script|<html/i', $s);
        }
        return false;
    }
}
