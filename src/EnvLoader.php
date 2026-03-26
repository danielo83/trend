<?php

class EnvLoader
{
    private static string $envPath = '';

    /**
     * Carica le variabili dal file .env.
     */
    public static function load(string $path): void
    {
        self::$envPath = $path;

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                // Rimuovi eventuali virgolette
                $value = trim($value, '"\'');
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * Legge una variabile d'ambiente.
     */
    public static function get(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Salva una variabile nel file .env.
     */
    public static function set(string $key, string $value): void
    {
        $path = self::$envPath;
        $lines = file_exists($path)
            ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : [];

        $found = false;
        foreach ($lines as &$line) {
            if (str_starts_with(trim($line), $key . '=')) {
                $line = $key . '=' . $value;
                $found = true;
                break;
            }
        }
        unset($line);

        if (!$found) {
            $lines[] = $key . '=' . $value;
        }

        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);

        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * Restituisce il path del file .env.
     */
    public static function getPath(): string
    {
        return self::$envPath;
    }
}
