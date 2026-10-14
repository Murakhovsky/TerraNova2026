<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Workflow\Model\WorkflowStatus;
use Kernel\Workflow\Model\StepStatus;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunSnapshot;
use Platform\Workflow\Model\WorkflowRecoveryAssessment;
use Platform\Workflow\Model\WorkflowRecoveryDisposition;
use Platform\Workflow\Model\WorkflowReceiptAttestation;

$assert = static function (bool $valid, string $message): void {
    if (!$valid) throw new RuntimeException($message);
};
$reject = static function (callable $action, string $label): void {
    try {
        $action();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Unsafe Workflow contract accepted: ' . $label);
};

$identity = new WorkflowRunIdentity('org-test', 'run-1');
$hash = hash('sha256', 'immutable-invocation');
$draft = new WorkflowRunDraft($identity, 'sales.intake', '1.0.0', $hash,
    'manager-1', 'corr-1', 'plan-1', 'approval-1');
$run = new WorkflowRunSnapshot($identity, $draft->definitionId, $draft->definitionVersion,
    WorkflowStatus::AMBIGUOUS, 5, $draft->correlationId, $draft->sourcePlanId);
$assert($run->status === WorkflowStatus::AMBIGUOUS && $run->revision === 5,
    'Persisted Run must preserve uncertainty and revision.');
$assert(StepStatus::CLAIMED->value === 'claimed' && StepStatus::AMBIGUOUS->value === 'ambiguous',
    'Canonical Step states lost durable vocabulary.');
$reject(static fn() => new WorkflowRunIdentity('', 'run-1'), 'unscoped Run');
$reject(static fn() => new WorkflowRunDraft($identity, 'wf', '1', 'unhashed', 'actor', 'corr'),
    'unversioned input hash');
$reject(static fn() => new WorkflowRunSnapshot($identity, 'wf', '1', WorkflowStatus::RUNNING, 0, 'corr'),
    'revision zero');
$reject(static fn() => new WorkflowReceiptAttestation($identity, 'step', true, 'action', null, 'corr'),
    'verified receipt without evidence');
$reject(static fn() => new WorkflowRecoveryAssessment($identity,
    WorkflowRecoveryDisposition::SAFE_PRE_EFFECT_RETRY, 'unknown external effect', true),
    'ambiguous remote effect retried');
$manual = new WorkflowRecoveryAssessment($identity,
    WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'unknown external effect', true);
$assert($manual->externalOutcomeUnknown, 'Remote uncertainty must be preserved.');

foreach ([
    \Platform\Workflow\Contract\WorkflowExecutionStoreInterface::class,
    \Platform\Workflow\Contract\WorkflowExecutionCoordinatorInterface::class,
    \Platform\Workflow\Contract\WorkflowReceiptVerifierInterface::class,
    \Platform\Workflow\Contract\WorkflowRecoveryInterface::class,
] as $contract) {
    $assert(interface_exists($contract), 'Missing durable Workflow contract: ' . $contract);
}
echo "Workflow durable contract types passed: tenant identity, immutable snapshots, ambiguity and receipt safety.\n";
