<?php
declare(strict_types=1);

/** Read-only fleet audit boundary: never a cutover or side-effect engine. */
$root=dirname(__DIR__,2);
$audit=(string)file_get_contents($root.'/symfony/src/Persistence/Federation/FederationExecutionFleetAudit.php');
$command=(string)file_get_contents($root.'/symfony/src/Command/FederationExecutionFleetAuditCommand.php');
foreach ([
    'organization_id=:org','cos_federation_runs','cos_workflow_runs',
    'cos_federation_execution_migrations','->historical->inspect(',
    'fleet_inventory_truncated','unmapped_dual_execution_authority',
    'canonical_execution_receipt_drift','->canonicalProof->inspect(',
    "'cutover_authorized'=>false","'read_only'=>true",
] as $marker) {
    if(!str_contains($audit,$marker)) throw new RuntimeException('Fleet scope gate missing: '.$marker);
}
foreach (['cos:federation:execution-fleet','return Command::FAILURE','->inspect('] as $marker) {
    if(!str_contains($command,$marker)) throw new RuntimeException('Operator-only CLI gate missing: '.$marker);
}
if (preg_match('/\\b(?:INSERT\\s+INTO|UPDATE\\s+|DELETE\\s+FROM|REPLACE\\s+INTO)\\s+cos_(?:workflow|federation)_/i',$audit)
    || str_contains($audit,'promotePristine(')
    || str_contains($audit,'->advance(')
    || str_contains($audit,'->start(')) {
    throw new RuntimeException('Read-only fleet audit must not mutate or dispatch business Actions.');
}
echo "Federation fleet read-only scope gate passed; operator cutover remains unauthorized.\n";
