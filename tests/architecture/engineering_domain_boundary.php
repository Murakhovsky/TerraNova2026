<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$domain = $root.'/symfony/src/Engineering/Domain';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domain));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    if ($content === false) throw new RuntimeException('Could not read '.$file->getPathname());

    foreach (['Doctrine\\', 'Symfony\\Component\\', 'App\\Persistence\\'] as $forbidden) {
        if (str_contains($content, $forbidden)) {
            throw new RuntimeException(sprintf('Engineering domain boundary violation in %s: %s', $file->getPathname(), $forbidden));
        }
    }
}

echo "Engineering domain boundary passed.\n";
