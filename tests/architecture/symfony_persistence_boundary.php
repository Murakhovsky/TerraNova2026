<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$adapter = file_get_contents($root . '/symfony/src/Infrastructure/Persistence/Legacy/LegacyIdentityRepositoryAdapter.php') ?: '';
$doctrine = file_get_contents($root . '/symfony/config/packages/doctrine.yaml') ?: '';
$compose = file_get_contents($root . '/docker-compose.symfony.yml') ?: '';

foreach (['tn_users', 'cos_organization_memberships', 'PDO'] as $needle) {
    if (!str_contains($adapter, $needle)) {
        fwrite(STDERR, "Legacy repository adapter contract missing {$needle}.\n");
        exit(1);
    }
}
if (str_contains($adapter, 'Doctrine\\')) {
    fwrite(STDERR, "Legacy repository adapter must not pretend legacy tables are Doctrine-owned entities.\n");
    exit(1);
}
if (!str_contains($doctrine, 'DATABASE_URL') || !str_contains($compose, 'mysql://')) {
    fwrite(STDERR, "Doctrine must target the existing MySQL Symfony database.\n");
    exit(1);
}
if (str_contains(strtolower($compose), 'postgres') || str_contains(strtolower($doctrine), 'postgres')) {
    fwrite(STDERR, "PostgreSQL must not be introduced in migration points 22-25.\n");
    exit(1);
}

echo "Legacy adapter -> COS boundary and MySQL-only Doctrine contract passed.\n";
