<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (['Database', 'ReadModel', 'Operations'] as $obsoleteDirectory) {
    if (is_dir($root . '/app/Infrastructure/' . $obsoleteDirectory)) {
        throw new RuntimeException('Obsolete Infrastructure root restored: ' . $obsoleteDirectory);
    }
}
if (is_dir($root . '/app/Infrastructure/Persistence/MySql')) {
    throw new RuntimeException('Centralized Infrastructure/Persistence/MySql root was restored.');
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = (string) file_get_contents($file->getPathname());
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    $isDomainActiveRecord = preg_match('~^app/Domains/[^/]+/Infrastructure/Persistence/Phalcon/~', $relative) === 1;
    $isSharedActiveRecordBase = str_starts_with($relative, 'app/Infrastructure/Integration/Telegram/ActiveRecord/');
    if ((str_contains($source, 'use Phalcon\\Mvc\\Model;') || str_contains($source, 'extends Model'))
        && !$isDomainActiveRecord
        && !$isSharedActiveRecordBase
    ) {
        throw new RuntimeException('Phalcon ActiveRecord escaped its persistence adapter boundary: ' . $relative);
    }
}

foreach (array_merge(
    glob($root . '/app/Domains/*/Infrastructure/Persistence/MySql', GLOB_ONLYDIR) ?: [],
    [$root . '/app/Infrastructure/Platform/Persistence/MySql'],
) as $mysqlDirectory) {
    $mysqlIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mysqlDirectory));
    foreach ($mysqlIterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $source = (string) file_get_contents($file->getPathname());
        foreach (['Interfaces\\', 'Phalcon\\Mvc\\Model'] as $forbidden) {
            if (preg_match('/^use\s+' . preg_quote($forbidden, '/') . '/m', $source)) {
                throw new RuntimeException('Persistence adapter bypasses its boundary: ' . $file->getPathname());
            }
        }
    }
}

foreach ([
    'app/Domains/Content/Application/Contract/ContentRepositoryInterface.php',
    'app/Domains/Property/Application/Contract/PropertyManagementRepositoryInterface.php',
    'app/Domains/Property/Application/Contract/PropertyMediaStorageInterface.php',
    'app/Domains/Property/Model/PropertyWorkflowPolicy.php',
    'app/Domains/Spatial/Application/Contract/SpatialSceneRepositoryInterface.php',
    'app/Domains/Content/Infrastructure/Persistence/MySql/MysqlContentRepository.php',
    'app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyManagementRepository.php',
    'app/Domains/Spatial/Infrastructure/Persistence/MySql/MysqlSpatialSceneRepository.php',
    'app/Infrastructure/Platform/Persistence/Pdo/PdoConnection.php',
] as $requiredFile) {
    if (!is_file($root . '/' . $requiredFile)) {
        throw new RuntimeException('Required persistence boundary is missing: ' . $requiredFile);
    }
}

echo "Persistence boundaries passed: MySQL uses domain ports and ActiveRecord is quarantined.\n";
