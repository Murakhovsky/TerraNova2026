<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$directory = $root . '/app/Platform/Knowledge';
$forbidden = ['Symfony\\', 'Phalcon\\', 'PDO', 'Infrastructure\\', 'Domains\\', 'Longman\\TelegramBot', 'GuzzleHttp\\', 'Google\\', 'OpenAI\\'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Platform Knowledge boundary violation in %s: %s\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

$agentDirectory = $root . '/app/Kernel/Agent';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($agentDirectory));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    foreach (['PDO', 'GuzzleHttp\\', 'Longman\\TelegramBot', 'Google\\', 'OpenAI\\'] as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Agent Core must not fetch external context directly: %s (%s)\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

echo "Platform Knowledge boundaries OK\n";
