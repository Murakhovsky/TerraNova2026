<?php
declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__) . '/vendor/autoload.php';

function expectPersistence(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Persistence contract failed: {$message}\n");
        exit(1);
    }
}

$url = (string) getenv('DATABASE_URL');
expectPersistence(str_starts_with($url, 'mysql://'), 'Symfony canonical database must remain MySQL during this migration slice.');
expectPersistence(!str_starts_with($url, 'postgresql://'), 'PostgreSQL migration must not be coupled to the Symfony migration.');

$params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
$connection = DriverManager::getConnection($params);
$database = (string) $connection->fetchOne('SELECT DATABASE()');
$version = (string) $connection->fetchOne('SELECT VERSION()');

expectPersistence($database !== '', 'Doctrine DBAL must connect to the canonical Symfony database.');
expectPersistence($version !== '', 'MySQL server version must be readable through Doctrine DBAL.');

echo "Doctrine DBAL connected to MySQL database {$database}.\n";
