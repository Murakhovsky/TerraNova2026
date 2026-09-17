<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/app/Domains/Sales/Diagnostics';
if (!is_dir($root)) {
    fwrite(STDERR, "Sales Diagnostics directory is missing.\n");
    exit(1);
}

$forbidden = [
    'OpenAI',
    'Anthropic',
    'Kernel\\Agent\\',
    'Kernel\\Llm\\',
    'Domains\\Diagnostic\\AI\\',
    'Symfony\\',
    'Phalcon\\',
    'PDO',
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    if (!is_string($content)) exit(1);
    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Sales Diagnostics boundary violation: %s contains %s.\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

echo "Sales Diagnostics methodology is deterministic and AI-independent.\n";
