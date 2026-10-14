<?php
declare(strict_types=1);

/**
 * Real MySQL acceptance of the dormant shared durable Run/Step Store.
 * Runs against dedicated test tenant IDs; NEVER dispatches external Actions.
 */
require (string) (getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/symfony/vendor/autoload.php');

use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\StepStatus;
use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowReceiptAttestation;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepCheckpoint;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;

$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => (string) (getenv('DB_HOST') ?: 'mysql'),
    'port' => (int) (getenv('DB_PORT') ?: '3306'),
    'dbname' => (string) (getenv('DB_DATABASE') ?: 'cos'),
    'user' => (string) (getenv('DB_USERNAME') ?: 'cos'),
    'password' => (string) (getenv('DB_PASSWORD') ?: ''),
    'charset' => 'utf8mb4',
]);
$token = bin2hex(random_bytes(5));
$tenant = 'wf-p0-' . $token;
$actor = new TenantContext(
    UserId::fromString('wf-admin-' . $token),
    OrganizationId::fromString($tenant),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$intruder = new TenantContext(
    UserId::fromString('wf-other-' . $token),
    OrganizationId::fromString('wf-foreign-' . $token),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$spyAdmission = new class implements WorkflowStepClaimAdmissionInterface {
    public int $calls = 0;
    public function assertAuthorized(WorkflowStepClaim $claim, WorkflowStepSnapshot $step): void
    {
        ++$this->calls;
        if ($step->capabilityId !== 'test.publish' || $step->sideEffectLevel !== 'external'
            || $claim->command->actor->organizationId()->value() !== $claim->command->identity->organizationId) {
            throw new DomainException('Test admission rejected unexpected external capability.');
        }
    }
};
$trustedVerifier = new class implements WorkflowReceiptVerifierInterface {
    public int $checks = 0;
    public function attest(WorkflowReceiptRequest $request): WorkflowReceiptAttestation
    {
        ++$this->checks;
        if ($request->inputDigest !== hash('sha256', 'workflow-p0-input')) {
            throw new DomainException('Input digest drift in Workflow receipt.');
        }
        $expected = [
            'review' => 'system:verified:review',
            'publish' => 'action:verified:publish',
        ];
        if (($expected[$request->stepId] ?? null) !== $request->resultReference) {
            throw new DomainException('Untrusted Workflow receipt reference.');
        }
        return new WorkflowReceiptAttestation(
            $request->identity, $request->stepId, true, 'fake-ci-proof',
            $request->resultReference, 'corr-' . $request->identity->runId,
        );
    }
};
$store = new DoctrineWorkflowExecutionStore($connection, $trustedVerifier, $spyAdmission);
$failClosed = new DoctrineWorkflowExecutionStore(
    $connection, new FailClosedWorkflowReceiptVerifier(), new FailClosedWorkflowStepClaimAdmission(),
);
$expect = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
};
$reject = static function (callable $callback, string $why): void {
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Unsafe durable Workflow mutation accepted: ' . $why);
};
$identity = new WorkflowRunIdentity($tenant, 'run-' . $token);
$plan = new WorkflowDefinitionSnapshot('test.durable-flow', '1.0.0', [
    ['id' => 'review', 'capability_id' => 'test.read',
        'capability_version' => '1.0.0', 'side_effect_level' => 'none', 'depends_on' => []],
    ['id' => 'publish', 'capability_id' => 'test.publish',
        'capability_version' => '1.0.0', 'side_effect_level' => 'external', 'depends_on' => ['review']],
]);
$draft = new WorkflowRunDraft(
    $identity, $plan->id, $plan->version, hash('sha256', 'workflow-p0-input'),
    $actor->userId()->value(), 'corr-' . $identity->runId,
    'plan-' . $token, 'approval-ci-' . $token, hash('sha256', 'approved-ci-plan'), $plan,
);
$command = static fn(int $revision): WorkflowRunCommand =>
    new WorkflowRunCommand($identity, $actor, $revision, 'corr-' . $identity->runId);

try {
    $run = $store->create($draft, $actor);
    $expect($run->revision === 1 && $run->status === WorkflowStatus::CREATED, 'Initial Run must be created at revision 1.');
    $expect(count($store->steps($identity)) === 2, 'Atomic Run creation must persist all steps.');
    $expect($store->get(new WorkflowRunIdentity($intruder->organizationId()->value(), $identity->runId)) === null,
        'Foreign tenant read revealed canonical Workflow Run.');

    $reject(static fn() => $store->create($draft, $intruder), 'foreign tenant creation');
    $reject(static fn() => $store->create($draft, $actor), 'duplicate same Run');
    $reject(static fn() => $store->create(new WorkflowRunDraft(
        new WorkflowRunIdentity($tenant, 'run-duplicate-' . $token), $plan->id, $plan->version,
        $draft->inputDigest, $draft->actorId, $draft->correlationId,
        $draft->sourcePlanId, $draft->approvalReference, $draft->sourcePlanDigest, $plan,
    ), $actor), 'duplicate approved Plan to two Runs');

    $run = $store->compareAndTransition(new WorkflowRunTransition(
        $command(1), WorkflowStatus::CREATED, WorkflowStatus::RUNNING, 'CI starts explicitly',
    ));
    $expect($run->revision === 2 && $run->status === WorkflowStatus::RUNNING, 'Run start CAS failed.');
    $reject(static fn() => $store->compareAndTransition(new WorkflowRunTransition(
        $command(1), WorkflowStatus::CREATED, WorkflowStatus::RUNNING, 'stale replay',
    )), 'stale Run revision replay');
    $reject(static fn() => $store->claim(new WorkflowStepClaim(
        new WorkflowRunCommand($identity, $intruder, 2, 'forged'), 'review', 'worker', 30,
    )), 'foreign tenant Run claim');

    $reject(static fn() => $store->claim(new WorkflowStepClaim($command(2), 'publish', 'worker', 120)),
        'DAG join skipped');
    $review = $store->claim(new WorkflowStepClaim($command(2), 'review', 'worker-A', 120));
    $expect($review->state === StepStatus::CLAIMED->value && $review->claimToken !== null,
        'Claim token not issued.');
    $expect($store->get($identity)?->revision === 3, 'Claim must advance Run revision.');
    $expect($store->steps($identity)[0]->claimToken === null, 'Read-only Step projection exposed secret claim token.');
    $reject(static fn() => $store->claim(new WorkflowStepClaim($command(2), 'review', 'worker-B', 120)),
        'second worker stale claim');
    $reject(static fn() => $store->checkpoint(new WorkflowStepCheckpoint(
        $command(3), 'review', 'deadbeef', StepStatus::COMPLETED, 'system:verified:review',
    )), 'forged token');
    $checkpoint = $store->checkpoint(new WorkflowStepCheckpoint(
        $command(3), 'review', (string) $review->claimToken, StepStatus::COMPLETED,
        'system:verified:review',
    ));
    $expect($checkpoint->state === StepStatus::COMPLETED->value
        && $store->get($identity)?->revision === 4, 'Read-only checkpoint did not atomically finish.');

    $reject(static fn() => $failClosed->claim(new WorkflowStepClaim($command(4), 'publish', 'worker-A', 120)),
        'unwired external Action admission');
    $expect($store->get($identity)?->revision === 4, 'Denied external Action admission mutated Run.');
    $publish = $store->claim(new WorkflowStepClaim($command(4), 'publish', 'worker-A', 120));
    $expect($spyAdmission->calls === 1 && $publish->claimToken !== null, 'Expected exactly one authorized external claim.');
    $reject(static fn() => $failClosed->checkpoint(new WorkflowStepCheckpoint(
        $command(5), 'publish', (string) $publish->claimToken,
        StepStatus::COMPLETED, 'action:verified:publish',
    )), 'unwired receipt verifier');
    $reject(static fn() => $store->checkpoint(new WorkflowStepCheckpoint(
        $command(5), 'publish', (string) $publish->claimToken,
        StepStatus::COMPLETED, 'action:untrusted',
    )), 'forged Action receipt');
    $expect($store->get($identity)?->revision === 5, 'Rejected receipt mutated Run revision.');
    $store->checkpoint(new WorkflowStepCheckpoint(
        $command(5), 'publish', (string) $publish->claimToken,
        StepStatus::COMPLETED, 'action:verified:publish',
    ));
    $expect($store->get($identity)?->revision === 6, 'Successful Action receipt did not commit.');

    // A tampered terminal flag must not bypass independent verification at finalization.
    $connection->executeStatement(
        'UPDATE cos_workflow_steps SET result_reference=:forged WHERE organization_id=:org AND run_id=:run AND step_id=:step',
        ['forged' => 'action:tampered', 'org' => $tenant, 'run' => $identity->runId, 'step' => 'publish'],
    );
    $reject(static fn() => $store->compareAndTransition(new WorkflowRunTransition(
        $command(6), WorkflowStatus::RUNNING, WorkflowStatus::COMPLETED, 'tampered completion',
    )), 'tampered completed Step receipt');
    $connection->executeStatement(
        'UPDATE cos_workflow_steps SET result_reference=:proof WHERE organization_id=:org AND run_id=:run AND step_id=:step',
        ['proof' => 'action:verified:publish', 'org' => $tenant, 'run' => $identity->runId, 'step' => 'publish'],
    );
    $done = $store->compareAndTransition(new WorkflowRunTransition(
        $command(6), WorkflowStatus::RUNNING, WorkflowStatus::COMPLETED, 'independently verified finalization',
    ));
    $expect($done->revision === 7 && $done->status === WorkflowStatus::COMPLETED, 'Final CAS + attested receipts failed.');
    $reject(static fn() => $store->compareAndTransition(new WorkflowRunTransition(
        $command(7), WorkflowStatus::COMPLETED, WorkflowStatus::RUNNING, 'illegal terminal restart',
    )), 'terminal state rewrite');
    $expect($trustedVerifier->checks >= 5, 'Receipts not re-attested before finalization.');
    echo "Durable Workflow MySQL acceptance passed: tenant isolation, atomic Run/Step, CAS, DAG, fenced claims, denied external admission, receipts and terminal immutability.\n";
} finally {
    $connection->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org', ['org' => $tenant]);
    $connection->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org', ['org' => $tenant]);
}
