<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/app/Kernel/Shared';
if (!is_dir($root)) {
    throw new RuntimeException('Shared Kernel directory is missing.');
}

$forbidden = [
    'Symfony\\' => 'Symfony',
    'Phalcon\\' => 'Phalcon',
    'Infrastructure\\' => 'Infrastructure',
    'Interfaces\\' => 'Interfaces',
    'Domains\\' => 'Domains',
    'App\\' => 'App',
];

$violations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read ' . $file->getPathname());
    }

    foreach ($forbidden as $needle => $label) {
        if (str_contains($content, $needle)) {
            $violations[] = sprintf('%s depends on %s', $file->getPathname(), $label);
        }
    }

    if (preg_match('/Kernel\\\\(?!Shared\\\\)/', $content) === 1) {
        $violations[] = sprintf('%s depends on another Kernel module', $file->getPathname());
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
    exit(1);
}

echo "Shared Kernel dependency boundary passed.\n";
