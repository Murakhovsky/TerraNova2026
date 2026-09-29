<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$platform = $root . '/app/Platform/Integration';
$forbiddenPlatform = ['Symfony\\', 'Phalcon\\', 'PDO', 'Infrastructure\\', 'Domains\\', 'Longman\\TelegramBot', 'GuzzleHttp\\', 'Google\\', 'OpenAI\\'];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($platform));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    foreach ($forbiddenPlatform as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Platform Integration boundary violation in %s: %s\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

$forbiddenDomainSdk = ['Longman\\TelegramBot', 'GuzzleHttp\\', 'Google\\Client', 'OpenAI\\Client'];
$domains = $root . '/app/Domains';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domains));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $file->getPathname());
    if (!str_contains($path, '/Application/') && !str_contains($path, '/Domain/')) continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    foreach ($forbiddenDomainSdk as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Domain/Application must use Integration contracts, vendor SDK found in %s: %s\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

echo "Platform Integration boundaries OK\n";
