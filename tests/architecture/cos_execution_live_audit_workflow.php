<?php
declare(strict_types=1);

/** Live audit must be SHA-pinned, real-tenant-scoped and mutation-free. */
$root=dirname(__DIR__,2);
$code=(string)file_get_contents($root.'/.github/workflows/cos-execution-live-audit.yml');
foreach (['workflow_dispatch:','environment: AWS TN2026','COS_EXECUTION_AUDIT_TENANT',
    'github.sha','sha256sum','kernel-worker',
    'engineering-worker','cos:workflow:authority-inventory','timeout --signal=TERM',
    'coverage_complete','cutover_authorized','release_authorized','report_sha256:',
    'rm -f --'] as $part) {
    if(!str_contains($code,$part)) throw new RuntimeException('Live execution audit guard missing '.$part);
}
// Deployment no longer ships .git. Require a commit-bound release manifest,
// repository-main ancestry proof, and independent comparison with checked-out
// authoritative PHP/DI/routes content before trusting any live tenant receipts.
foreach ([
    'git merge-base --is-ancestor "$EXPECTED_HEAD" origin/main',
    'git checkout --quiet --detach "$EXPECTED_HEAD"',
    '.cos-deployment-attestation.json',
    'TRUSTED_CODE_SHA',
    'TRUSTED_ROUTES_SHA',
    'DECLARED_SOURCE_SHA',
    'DECLARED_ROUTES_SHA',
    'ACTUAL_ROUTES_SHA',
    'Deployed source differs from expected GitHub main commit or release manifest',
] as $part) {
    if (!str_contains($code,$part)) throw new RuntimeException('Release attestation guard missing '.$part);
}
if (str_contains($code,'test -d "$ROOT/.git"')) {
    throw new RuntimeException('Live audit must not depend on excluded .git files');
}
$deploy=(string)file_get_contents($root.'/.github/workflows/runtime.yml');
foreach (['Create verifiable deployment source attestation',
    '.cos-deployment-attestation.json', 'GITHUB_SHA',
    'symfony/config/services.yaml','symfony/config/routes.yaml',
    'Sync repository'] as $part) {
    if (!str_contains($deploy,$part)) throw new RuntimeException('Deploy attestation missing '.$part);
}
foreach (['docker compose up','docker build','cos:schema:migrate',
    'doctrine:migrations:migrate','history:cutover','--apply','curl -X POST'] as $bad) {
    if(str_contains($code,$bad))throw new RuntimeException('Read-only audit gained forbidden mutation: '.$bad);
}
echo "Guarded live tenant audit workflow source PASS; runtime evidence still required.\n";
