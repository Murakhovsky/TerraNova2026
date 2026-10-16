<?php
declare(strict_types=1);

/** Live audit must be SHA-pinned, real-tenant-scoped and mutation-free. */
$root=dirname(__DIR__,2);
$code=(string)file_get_contents($root.'/.github/workflows/cos-execution-live-audit.yml');
foreach (['workflow_dispatch:','environment: AWS TN2026','COS_EXECUTION_AUDIT_TENANT',
    'github.sha','git -C "$ROOT" rev-parse HEAD','sha256sum','kernel-worker',
    'engineering-worker','cos:workflow:authority-inventory','timeout --signal=TERM',
    'coverage_complete','cutover_authorized','release_authorized','report_sha256:',
    'rm -f --'] as $part) {
    if(!str_contains($code,$part)) throw new RuntimeException('Live execution audit guard missing '.$part);
}
foreach (['docker compose up','docker build','cos:schema:migrate',
    'doctrine:migrations:migrate','history:cutover','--apply','curl -X POST'] as $bad) {
    if(str_contains($code,$bad))throw new RuntimeException('Read-only audit gained forbidden mutation: '.$bad);
}
echo "Guarded live tenant audit workflow source PASS; runtime evidence still required.\n";
