<?php

namespace App\Game;

use JumPHP\Save\Storage;

/**
 * The best score, in var/best.json (IndexedDB in the browser).
 */
final class Best
{
    private const FILE = 'best.json';

    public static function load(Storage $storage): int
    {
        $data = $storage->readJson(self::FILE, []);
        return is_array($data) ? (int)($data['best'] ?? 0) : 0;
    }

    /** Saves $score when it beats the record. @return bool a new record */
    public static function submit(Storage $storage, int $score): bool
    {
        if ($score <= self::load($storage)) {
            return false;
        }
        $storage->writeJson(self::FILE, ['best' => $score]);
        return true;
    }
}
