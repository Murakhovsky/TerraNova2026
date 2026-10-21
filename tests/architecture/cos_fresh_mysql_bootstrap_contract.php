<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = (string) file_get_contents($root.'/deploy/bootstrap-cos-fresh-mysql.sh');
$deploy = (string) file_get_contents($root.'/deploy/dev.sh');
$workflow = (string) file_get_contents($root.'/.github/workflows/cos-fresh-database-bootstrap.yml');

foreach ([$root.'/deploy/bootstrap-cos-fresh-mysql.sh', $root.'/deploy/dev.sh'] as $shellFile) {
    $output = [];
    $code = 0;
    exec('bash -n '.escapeshellarg($shellFile).' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException('Invalid shell syntax: '.basename($shellFile).' '.implode("\n", $output));
    }
}
foreach ([
    'COS_NEW_DB_BOOTSTRAP=1',
    'COS_DEPLOY_MODE=registry COS_DEPLOY_TIER=core',
    'Pre-existing or completed COS deployment detected',
    'Containers or volumes exist',
    'MYSQL_ROOT_PASSWORD',
    'COS_SECRET_MASTER_KEY',
    'MIGRATING_NEW_COS_DATABASE',
    'HTTPS_PENDING_DNS',
    'COS_NEW_DATABASE_BOOTSTRAP_SUCCESS',
] as $must) {
    if (!str_contains($script, $must)) throw new RuntimeException('Fresh DB bootstrap guard missing: '.$must);
}
foreach ([
    'cos-dev-deploy', 'AWS TN2026', 'DEV_SSH_KEY',
    "--exclude='.env.docker'", 'bootstrap-cos-fresh-mysql.sh',
] as $must) {
    if (!str_contains($workflow, $must)) throw new RuntimeException('First-run workflow guard missing: '.$must);
}
if (!str_contains($deploy, 'COS_NEW_DB_BOOTSTRAP:-0')
    || str_contains($script, 'docker volume rm')
    || str_contains($script, 'docker compose down -v')
    || str_contains($script, 'docker system prune')
    || str_contains($workflow, 'cat .env.docker')) {
    throw new RuntimeException('Unsafe first-run database operation');
}
echo "COS fresh MySQL bootstrap architecture PASS.\n";
