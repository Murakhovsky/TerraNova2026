<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\TableOwnership;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$violations = [];
$domainsRoot = $root . '/app/Domains';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainsRoot));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (!preg_match('~/Domains/([^/]+)/Infrastructure/Persistence/MySql/~', $path, $domain)) {
        continue;
    }
    $source = file_get_contents($file->getPathname()) ?: '';
    preg_match_all('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z][a-z0-9_]*)`?/i', $source, $matches);
    foreach (array_unique(array_map('strtolower', $matches[1] ?? [])) as $table) {
        $owner = TableOwnership::ownerOf($table);
        if ($owner !== null && $owner !== $domain[1]) {
            $violations[] = $domain[1] . ' writes ' . $table . ' owned by ' . $owner . ' in ' . $path;
        }
    }
}

if ($violations) {
    fwrite(STDERR, implode("\n", $violations) . "\n");
    exit(1);
}

echo "Table ownership passed: every Domain MySQL adapter writes only its owned tables.\n";
