<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$directory = $root . '/app/Platform/Documents';
$forbidden = ['Symfony\\', 'Phalcon\\', 'PDO', 'Infrastructure\\', 'Domains\\', 'GuzzleHttp\\', 'Google\\', 'OpenAI\\', 'Twilio\\'];

if (!is_dir($directory)) {
    fwrite(STDERR, "Platform Documents capability is missing.\n");
    exit(1);
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Platform Documents boundary violation in %s: %s\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}
echo "Platform Documents boundaries OK\n";
