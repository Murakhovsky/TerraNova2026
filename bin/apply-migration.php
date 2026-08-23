<?php
declare(strict_types=1);

use Common\Services\DatabaseService;
use Phalcon\Di\FactoryDefault;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$di = new FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';
$database = $di->getShared('databaseService');
assert($database instanceof DatabaseService);

$name = basename((string) ($argv[1] ?? ''));
$path = APP_PATH . '/migrations/' . $name;
if ($name === '' || !preg_match('/^[a-zA-Z0-9_.-]+\.sql$/', $name) || !is_file($path)) {
    fwrite(STDERR, "Usage: php bin/apply-migration.php <migration.sql>\n");
    exit(2);
}

$sql = (string) file_get_contents($path);
if (trim($sql) === '') {
    fwrite(STDERR, "Migration is empty.\n");
    exit(2);
}

$migrationId = pathinfo($name, PATHINFO_FILENAME);
try {
    $applied = $database->fetchOne('SELECT migration FROM tn_migrations WHERE migration = :migration LIMIT 1', [
        'migration' => $migrationId,
    ]);
    if ($applied) {
        echo 'Already applied ' . $name . PHP_EOL;
        exit(0);
    }
} catch (Throwable) {
    // The first project migration may create the migration registry itself.
}

$database->connection()->exec($sql);
echo 'Applied ' . $name . PHP_EOL;
