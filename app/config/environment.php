<?php
declare(strict_types=1);

/**
 * Loads simple KEY=VALUE entries from the project .env without overriding
 * variables supplied by Docker, the web server, or the process supervisor.
 */
if (!function_exists('loadProjectEnvironment')) {
    function loadProjectEnvironment(string $basePath): void
    {
        $path = $basePath . '/.env';
        if (!is_file($path) || !is_readable($path)) return;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key) || getenv($key) !== false) continue;

            if (strlen($value) >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

loadProjectEnvironment(BASE_PATH);
