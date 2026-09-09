<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require APP_PATH . '/config/environment.php';
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$config = require APP_PATH . '/config/config.php';
$database = new PdoConnection($config->database);

$email = trim((string) ($argv[1] ?? ''));
$fullName = trim((string) ($argv[2] ?? 'COS Administrator'));
$password = (string) ($_ENV['COS_ADMIN_PASSWORD'] ?? getenv('COS_ADMIN_PASSWORD') ?: '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: COS_ADMIN_PASSWORD='<password>' php bin/create-admin.php <email> [full-name]\n");
    exit(2);
}

if ($fullName === '') {
    fwrite(STDERR, "Admin full name must not be empty.\n");
    exit(2);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "COS_ADMIN_PASSWORD must contain at least 12 characters.\n");
    exit(2);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    fwrite(STDERR, "Failed to hash the password.\n");
    exit(1);
}

$sql = <<<'SQL'
INSERT INTO tn_users (
    email,
    password_hash,
    full_name,
    role,
    status
) VALUES (
    :email,
    :password_hash,
    :full_name,
    'admin',
    'active'
)
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    full_name = VALUES(full_name),
    role = 'admin',
    status = 'active',
    updated_at = CURRENT_TIMESTAMP
SQL;

$statement = $database->connection()->prepare($sql);
$statement->execute([
    'email' => $email,
    'password_hash' => $passwordHash,
    'full_name' => $fullName,
]);

$account = $database->fetchOne(
    'SELECT id, email, full_name, role, status FROM tn_users WHERE email = :email LIMIT 1',
    ['email' => $email]
);

if (!$account || ($account['role'] ?? null) !== 'admin' || ($account['status'] ?? null) !== 'active') {
    fwrite(STDERR, "Admin provisioning verification failed.\n");
    exit(1);
}

echo sprintf(
    "Admin account ready: #%s %s (%s)\n",
    (string) ($account['id'] ?? '?'),
    (string) ($account['email'] ?? $email),
    (string) ($account['full_name'] ?? $fullName)
);
