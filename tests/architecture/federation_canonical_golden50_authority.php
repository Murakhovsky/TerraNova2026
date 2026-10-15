<?php
declare(strict_types=1);

/**
 * Contract only: direct legacy joins in Golden 50 would hide canonical
 * results, while dual-writing legacy execution would break single authority.
 * Real staging and 50 native records are separate mandatory release gates.
 */
$root=dirname(__DIR__,2);
$reader=(string)file_get_contents($root.'/symfony/src/Persistence/Federation/FederationVerifiedRunReadModel.php');
$fanout=(string)file_get_contents($root.'/symfony/src/Persistence/Federation/FederationCandidateFanoutService.php');
$aggregate=(string)file_get_contents($root.'/symfony/src/Persistence/Federation/FederationGoalOutcomeAggregationService.php');
foreach ([
    [$reader,'DoctrineWorkflowActionReceiptVerifier'],
    [$reader,'WorkflowReceiptRequest('],
    [$reader,'cos_workflow_runs'],
    [$reader,'cos_workflow_steps'],
    [$reader,'cos_federation_runs'],
    [$reader,'cos_federation_steps'],
    [$reader,'conflicting execution authorities'],
    [$reader,"'wf:':'fed:'"],
    [$fanout,'runReader->requireCompletedAction('],
    [$fanout,'runReader->actionKey('],
    [$aggregate,'runReader->forPlan('],
    [$aggregate,'runReader->stepsForRun('],
    [$aggregate,'runReader->requireCompletedAction('],
    [$aggregate,'runReader->actionKey('],
] as [$source,$needle]) {
    if (!str_contains($source,$needle)) {
        throw new RuntimeException('Golden 50 authority read model missing: '.$needle);
    }
}
foreach ([$reader,$fanout,$aggregate] as $source) {
    if (preg_match('~\b(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)\s+cos_(?:workflow|federation)_(?:runs|steps)\b~i',$source)
        || str_contains($source,'->finishStep(')
        || str_contains($source,'->checkpoint(')) {
        throw new RuntimeException('Golden 50 evidence path must never write technical execution state.');
    }
}
if (str_contains($fanout,'$this->receipts->reconcile(')
    || str_contains($aggregate,'$this->receipts->reconcile(')
    || str_contains($aggregate,'LEFT JOIN cos_federation_runs')) {
    throw new RuntimeException('Golden 50 business outcome is still bound only to historical execution.');
}
echo "Golden 50 canonical/historical independently-attested read model architecture PASS; live 50 acceptance PENDING.\n";
