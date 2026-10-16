<?php
declare(strict_types=1);

/** A static repo-wide inventory. Never a production fleet acceptance badge. */
$root=dirname(__DIR__,2);
require_once $root.'/app/Platform/Workflow/Service/WorkflowExecutionAuthorityInventory.php';
$report=\Platform\Workflow\Service\WorkflowExecutionAuthorityInventory::inspect($root);
if($report['errors']!==[] || $report['status']!=='static_covered_not_fleet_accepted'
    || $report['consumer_fleet_verified']!==false
    || $report['live_tenant_verified']!==false) {
    throw new RuntimeException('Execution authority inventory diverged: '.json_encode($report['errors']));
}
foreach(['canonical_store','legacy_frozen','goal_web_and_bridge','engineering_agent',
    'kernel_queue_and_agent','tool_independent_runtime','kernel_in_memory',
    'operator_audits'] as $kind) {
    if(empty($report['runtime_surfaces'][$kind])) {
        throw new RuntimeException('Execution inventory lacks required consumer surface: '.$kind);
    }
}
foreach(['cos_federation_runs','cos_federation_steps','cos_workflow_runs',
    'cos_workflow_steps'] as $table) {
    if(empty($report['technical_writers'][$table])
        || empty($report['read_consumer_candidates'][$table])) {
        throw new RuntimeException('No auditable technical writer/read consumer: '.$table);
    }
}
foreach(['WorkflowExecutionStoreInterface','WorkflowTechnicalExternalStoreInterface',
    'WorkflowExecutionCoordinatorInterface','WorkflowEvidenceSignalStoreInterface',
    'ActionExecutionJobHandler','AgentRunJobHandler','ToolRuntime'] as $symbol) {
    if(empty($report['consumer_symbol_references'][$symbol])) {
        throw new RuntimeException('Runtime contract/consumer is absent: '.$symbol);
    }
}
if(!is_file($root.'/bin/cos-execution-authority-report.php')) {
    throw new RuntimeException('Operator static execution evidence report missing.');
}
echo sprintf("COS execution authority static inventory passed: %d PHP files; distinct writer paths fenced; runtime fleet NOT verified.\n",
    $report['php_files_scanned']);
