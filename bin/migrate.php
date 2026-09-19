<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\MySql\Migration\MigrationRunner;
use Infrastructure\Platform\Persistence\MySql\Migration\SqlStatementSplitter;
use PDO;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$env = static function (string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? getenv($name);
    return is_string($value) && $value !== '' ? $value : $default;
};

$host = $env('DB_HOST', '127.0.0.1');
$port = max(1, (int) $env('DB_PORT', '3306'));
$database = $env('DB_DATABASE');
$user = $env('DB_USERNAME');
$password = $env('DB_PASSWORD');

if ($database === '' || $user === '') {
    fwrite(STDERR, "DB_DATABASE and DB_USERNAME are required.\n");
    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);

$runner = new MigrationRunner(
    $pdo,
    APP_PATH . '/migrations',
    new SqlStatementSplitter(),
);

$action = strtolower(trim((string) ($argv[1] ?? 'up')));
$result = match ($action) {
    'up', 'migrate' => $runner->migrate(),
    'status' => $runner->status(),
    default => null,
};

if ($result === null) {
    fwrite(STDERR, "Usage: php bin/migrate.php [up|status]\n");
    exit(2);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
