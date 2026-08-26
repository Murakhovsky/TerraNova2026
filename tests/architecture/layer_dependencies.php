<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

/** @return list<string> */
function phpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
    }
    return $files;
}

/** @param list<string> $forbiddenPrefixes */
function assertNoDependencies(string $directory, array $forbiddenPrefixes): void
{
    foreach (phpFiles($directory) as $file) {
        $source = file_get_contents($file);
        foreach ($forbiddenPrefixes as $prefix) {
            $pattern = '/^use\s+' . preg_quote($prefix, '/') . '\\\\/m';
            if (preg_match($pattern, $source)) {
                throw new RuntimeException(sprintf(
                    '%s must not depend on %s (%s).',
                    str_replace(dirname($directory) . DIRECTORY_SEPARATOR, '', $directory),
                    $prefix,
                    str_replace($GLOBALS['root'] . DIRECTORY_SEPARATOR, '', $file),
                ));
            }
        }
    }
}

assertNoDependencies($root . '/app/Kernel', ['Domains', 'Infrastructure', 'Interfaces', 'Modules', 'Common', 'Phalcon']);
assertNoDependencies($root . '/app/Domains', ['Infrastructure', 'Interfaces', 'Modules', 'Common', 'Phalcon']);
assertNoDependencies($root . '/app/Interfaces', ['Infrastructure', 'Modules']);

foreach (phpFiles($root . '/app/Kernel') as $file) {
    $source = file_get_contents($file);
    if (preg_match('/^use\s+PDO\s*;/m', $source)
        || str_contains($source, 'new PDO(')
        || str_contains($source, 'new \\PDO(')
    ) {
        throw new RuntimeException('Kernel must access persistence through contracts: ' . $file);
    }
}

foreach (phpFiles($root . '/app/Interfaces/Api/Controller') as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match('/public\s+function\s+\w+Action\s*\([^)]*\)\s*:\s*void/', $source)) {
        throw new RuntimeException('API actions must return the Phalcon response so JSON bodies reach HTTP clients: ' . $file);
    }
}

$requiredSalesAreas = ['Application', 'Automation', 'Bootstrap', 'Model'];
foreach ($requiredSalesAreas as $area) {
    if (!is_dir($root . '/app/Domains/Sales/' . $area)) {
        throw new RuntimeException(sprintf('Sales domain is missing its %s area.', $area));
    }
}

echo "Architecture boundaries passed: Kernel is independent and Domains use ports only.\n";
