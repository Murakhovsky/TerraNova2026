<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$compose = (string) file_get_contents($root.'/docker-compose.yml');
$deploy = (string) file_get_contents($root.'/deploy/dev.sh');
$workflow = (string) file_get_contents($root.'/.github/workflows/runtime.yml');
$ignore = (string) file_get_contents($root.'/.dockerignore');

foreach ([
    'COS_PHP_IMAGE:-cos-symfony-php:local',
    'COS_NGINX_IMAGE:-cos-nginx:latest',
    'mysql_data:',
    'redis_data:',
    'spatial_uploads:',
] as $expected) {
    if (!str_contains($compose, $expected)) {
        throw new RuntimeException('Compose contract missing '.$expected);
    }
}
foreach (['/tests','/docs','/archive','/output','/public/uploads'] as $path) {
    if (!preg_match('/^'.preg_quote($path, '/').'$/m', $ignore)) {
        throw new RuntimeException('Build context must exclude '.$path);
    }
}
foreach ([
    'COS_DEPLOY_MODE', 'COS_DEPLOY_TIER',
    'RUNTIME_SERVICES=(php worker)', 'DEFERRED_SERVICES=',
    'pull php nginx', '--no-build --no-deps',
    'COS_PHP_IMAGE must be a commit-SHA-pinned',
    'PHP and Nginx image SHAs must match',
] as $marker) {
    if (!str_contains($deploy, $marker)) {
        throw new RuntimeException('Deploy safety missing '.$marker);
    }
}
foreach ([
    'publish-runtime-images:', 'packages: write',
    'docker/login-action@v3', 'push: true',
    'ghcr.io/murakhovsky/terranova2026-php:',
    'ghcr.io/murakhovsky/terranova2026-nginx:',
    'needs: [runtime, federation-mysql, capital-markets-unit, publish-runtime-images]',
    'packages: read', 'COS_DEPLOY_MODE=registry COS_DEPLOY_TIER=core',
    'Require existing COS secrets and data initialization',
    'Logout from GHCR on VPS',
] as $marker) {
    if (!str_contains($workflow, $marker)) {
        throw new RuntimeException('Release boundary missing '.$marker);
    }
}
if (str_contains($deploy, 'docker system prune')
    || str_contains($deploy, 'docker volume prune')
    || !str_contains($workflow, "if: github.event_name == 'push' && github.ref == 'refs/heads/main'")) {
    throw new RuntimeException('Dangerous data deletion or unguarded image publication');
}
echo "Lean COS image publication and minimal deploy contract PASS.\n";
