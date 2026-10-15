<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/symfony/src/Engineering/Domain/Workflow/EngineeringWorkflowState.php';
require $root.'/symfony/src/Engineering/Domain/Workflow/EngineeringWorkflowDefinition.php';
require $root.'/symfony/src/Engineering/Application/Service/EngineeringSecurityRecoveryService.php';

use App\Engineering\Domain\Workflow\EngineeringWorkflowDefinition;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Application\Service\EngineeringSecurityRecoveryService;

$definition = new EngineeringWorkflowDefinition();
if (!$definition->canTransition(EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::ARCHITECTURE_PENDING)
    || $definition->canTransition(EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::DEVELOPMENT_RUNNING)
    || $definition->canTransition(EngineeringWorkflowState::BLOCKED, EngineeringWorkflowState::DONE)) {
    throw new RuntimeException('Only controlled Architect recovery may leave BLOCKED; Developer bypass forbidden.');
}

$feature = [
    'previous_context' => [
        ['other' => 'unrelated'],
        ['security_recovery' => ['recovery_id' => 'R1', 'state' => 'PREPARED']],
        ['note' => 'irrelevant'],
        ['security_recovery' => ['recovery_id' => 'R1', 'state' => 'QUEUED']],
    ],
];
$checkpoint = EngineeringSecurityRecoveryService::latestCheckpoint($feature);
if (($checkpoint['state'] ?? '') !== 'QUEUED' || ($checkpoint['recovery_id'] ?? '') !== 'R1'
    || EngineeringSecurityRecoveryService::latestCheckpoint(['previous_context' => []]) !== null) {
    throw new RuntimeException('Recovery must honor append-only latest checkpoint, not old PREPARED state.');
}
echo "Engineering targeted Security recovery state policy passed.\n";
