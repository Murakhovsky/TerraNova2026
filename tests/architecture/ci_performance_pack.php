<?php
declare(strict_types=1);

/**
 * Static safety checks for the P0 CI Performance Pack.
 * A faster pipeline may not erase a required acceptance or deploy gate.
 */
$root = dirname(__DIR__, 2);
$workflow = (string) file_get_contents($root.'/.github/workflows/runtime.yml');
$timing = (string) file_get_contents($root.'/bin/ci/step_timings.py');

$required = [
    'workflow_dispatch:',
    '  federation-mysql:',
    'name: Federation MySQL',
    'image: mysql:8.4',
    'php symfony/bin/console cos:schema:migrate',
    'php symfony/bin/console doctrine:migrations:migrate',
    'php tests/integration/federation_canonical_goal_e2e_mysql.php',
    'php tests/integration/federation_historical_cutover_mysql.php',
    'php tests/integration/federation_non_action_goal_mysql.php',
    '  capital-markets-unit:',
    'name: Capital Markets Pack',
    'php tests/integration/capital_markets_capital_risk_manager_acceptance.php',
    'php tests/integration/capital_markets_decision_workspace_manager_acceptance.php',
    'name: runtime',
    'docker/setup-buildx-action@v3',
    'docker/build-push-action@v6',
    'cache-from: type=gha,scope=cos-runtime-php-v1',
    'cache-to: type=gha,scope=cos-runtime-php-v1',
    'cache-from: type=gha,scope=cos-runtime-nginx-v1',
    'cache-to: type=gha,scope=cos-runtime-nginx-v1',
    'load: true',
    'tags: cos-symfony-php:local',
    'tags: cos-nginx:latest',
    'needs: [runtime, federation-mysql, capital-markets-unit]',
    'if: github.event_name == \'push\' && github.ref == \'refs/heads/main\'',
    'CI_TIMING_JOB: Federation MySQL',
    'CI_TIMING_JOB: Capital Markets Pack',
    'CI_TIMING_JOB: runtime',
    'python3 bin/ci/step_timings.py',
    'if: always()',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        throw new RuntimeException('CI performance safety contract missing: '.$needle);
    }
}

if (str_contains($workflow, 'docker compose build php nginx')
    || substr_count($workflow,'- name: Capital Markets Capital Risk and Decision Workspace acceptance')!==1
    || substr_count($workflow,'- name: Federation canonical Goal-to-Outcome MySQL E2E acceptance')!==1
    || substr_count($workflow,'php tests/integration/federation_canonical_goal_e2e_mysql.php')!==1
    || !str_contains($timing,'completed_at')
    || !str_contains($timing,'GITHUB_STEP_SUMMARY')
    || !str_contains($timing,'GITHUB_SHA')) {
    throw new RuntimeException('CI performance pack weakened a critical gate or timing evidence.');
}

echo "CI Performance Pack safety contracts passed (parallel independent gates, BuildKit cache, complete deploy needs, measured stages).\n";
