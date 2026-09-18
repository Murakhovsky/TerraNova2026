<?php
declare(strict_types=1);

$directory = dirname(__DIR__) . '/src/Http/Api/V1/Controller';
$forbidden = [
    'PDO',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE FROM ',
    'OpenAI',
    'Telegram',
    'curl_',
    'file_get_contents(',
    'Infrastructure\\',
    'Domains\\',
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("API v1 controller boundary violation in %s: %s\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

echo "API v1 controllers contain transport concerns only.\n";
