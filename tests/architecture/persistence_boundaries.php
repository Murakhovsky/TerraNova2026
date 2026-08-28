<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$persistence = $root . '/app/Infrastructure/Persistence';

foreach (['Database', 'ReadModel', 'Operations'] as $obsoleteDirectory) {
    if (is_dir($root . '/app/Infrastructure/' . $obsoleteDirectory)) {
        throw new RuntimeException('Obsolete Infrastructure root restored: ' . $obsoleteDirectory);
    }
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = (string) file_get_contents($file->getPathname());
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    if ((str_contains($source, 'use Phalcon\\Mvc\\Model;') || str_contains($source, 'extends Model'))
        && !str_starts_with($relative, 'app/Infrastructure/Persistence/Phalcon/')
    ) {
        throw new RuntimeException('Phalcon ActiveRecord escaped its persistence adapter boundary: ' . $relative);
    }
}

$mysqlIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($persistence . '/MySql'));
foreach ($mysqlIterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = (string) file_get_contents($file->getPathname());
    foreach (['Interfaces\\', 'Infrastructure\\Media\\', 'Infrastructure\\Integration\\', 'Phalcon\\Mvc\\Model'] as $forbidden) {
        if (preg_match('/^use\s+' . preg_quote($forbidden, '/') . '/m', $source)) {
            throw new RuntimeException('MySQL adapter bypasses a domain-owned port: ' . $file->getPathname());
        }
    }
}

foreach ([
    'app/Domains/Content/Application/Contract/ContentRepositoryInterface.php',
    'app/Domains/Property/Application/Contract/PropertyManagementRepositoryInterface.php',
    'app/Domains/Property/Application/Contract/PropertyMediaStorageInterface.php',
    'app/Domains/Property/Model/PropertyWorkflowPolicy.php',
    'app/Domains/Spatial/Application/Contract/SpatialSceneRepositoryInterface.php',
    'app/Infrastructure/Persistence/MySql/Content/MysqlContentRepository.php',
    'app/Infrastructure/Persistence/MySql/Property/MysqlPropertyManagementRepository.php',
    'app/Infrastructure/Persistence/MySql/Spatial/MysqlSpatialSceneRepository.php',
] as $requiredFile) {
    if (!is_file($root . '/' . $requiredFile)) {
        throw new RuntimeException('Required persistence boundary is missing: ' . $requiredFile);
    }
}

echo "Persistence boundaries passed: MySQL uses domain ports and ActiveRecord is quarantined.\n";
