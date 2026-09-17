<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$directory = $root . '/app/Platform/Notification';
$forbidden = ['Symfony\\', 'Phalcon\\', 'PDO', 'Infrastructure\\', 'Domains\\', 'Longman\\TelegramBot', 'GuzzleHttp\\', 'Google\\', 'OpenAI\\', 'Twilio\\'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Platform Notification boundary violation in %s: %s\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

echo "Platform Notification boundaries OK\n";
