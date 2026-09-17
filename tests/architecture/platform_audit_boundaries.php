<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$directory = $root . '/app/Platform/Audit';
$forbidden = ['Symfony\\', 'Phalcon\\', 'PDO', 'Infrastructure\\', 'Domains\\', 'Longman\\TelegramBot', 'GuzzleHttp\\', 'OpenAI\\'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Platform Audit boundary violation in %s: %s\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

echo "Platform Audit boundaries OK\n";
