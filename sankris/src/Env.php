<?php
declare(strict_types=1);

namespace Sankris;

/** Reads GYM One's .env (same KEY=VALUE format the rest of the app uses). */
final class Env
{
    public static function read(string $path): array
    {
        $data = [];
        if (!is_file($path)) {
            return $data;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $data[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
        return $data;
    }
}
