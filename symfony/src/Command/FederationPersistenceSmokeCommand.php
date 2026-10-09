<?php
declare(strict_types=1);

namespace App\Command;

use App\Persistence\Federation\FederationExperiencePreferenceStore;
use App\Persistence\Federation\FederationOutcomeOriginReader;
use Domains\CapitalMarkets\Automation\Action\RecordValidatedResearchResultHandler;
use Domains\Documents\Automation\Action\RequestSignatureHandler;
use App\Persistence\Federation\FederationNativeRunLinkedOutcomeEvidenceProvider;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use App\Persistence\Federation\FederationOutcomeOriginRecorder;
use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationPlanApprovalCoordinator;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationApprovedActionIntentFactory;
use App\Persistence\Federation\FederationExternalActionReceiptReconciler;
use App\Persistence\Federation\FederationRunFinalizer;
use App\Persistence\Federation\FederationSequentialOrchestrator;
use App\Persistence\Federation\FederatedActionAdmission;
use App\Persistence\Federation\FederationPlanApproveHandler;
use App\Persistence\Federation\FederationReadOnlyWorkflowRunner;
use App\Web\Experience\Adaptive\ExperienceMode;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use LogicException;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Orchestration\Goal\FederationCandidateFanoutPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Kernel\Workflow\Model\Workflow;
use Kernel\Workflow\Model\WorkflowDefinition;
use Kernel\Workflow\Model\WorkflowInstance;
use Kernel\Workflow\Model\Assignment;
use Kernel\Workflow\Model\AssignmentType;
use Kernel\Workflow\Model\Step\HumanStep;
use Kernel\Workflow\Model\Step\SystemStep;
use Twig\Environment;

#[AsCommand(
    name: 'cos:federation:persistence:smoke',
    description: 'Transactional isolated MySQL smoke of Goal/Execution/Experience persistence.',
)]
final class FederationPersistenceSmokeCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly FederationGoalStore $goals,
        private readonly FederationCandidateFanoutPlanner $candidateFanout,
        private readonly FederationOutcomeOriginReader $originReader,
        private readonly FederationOutcomeOriginRecorder $originRecorder,
        private readonly RecordValidatedResearchResultHandler $researchResultHandler,
        private readonly RequestSignatureHandler $signatureRequestHandler,
        private readonly ResearchLabRepositoryInterface $researchRepository,
        private readonly FederationExperiencePreferenceStore $preferences,
        private readonly Environment $twig,
        private readonly FederationPlanApprovalCoordinator $approvalCoordinator,
        private readonly FederationCapabilityBindingResolver $capabilityBindings,
        private readonly FederationApprovedActionIntentFactory $actionIntents,
        private readonly FederatedActionAdmission $actionAdmission,
        private readonly FederationExternalActionReceiptReconciler $receiptReconciler,
        private readonly FederationRunFinalizer $runFinalizer,
        private readonly FederationSequentialOrchestrator $sequentialOrchestrator,
        private readonly \App\Persistence\Federation\FederationRunRecoveryService $recovery,
        private readonly FederationPlanApproveHandler $approvalHandler,
        private readonly FederationReadOnlyWorkflowRunner $readOnlyWorkflow,
        private readonly \App\Persistence\Federation\FederationWorkflowPreflight $workflowPreflight,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $org = 'fed-smoke-' . bin2hex(random_bytes(8));
        $goalId = 'goal-' . bin2hex(random_bytes(8));
        $runId = 'run-' . bin2hex(random_bytes(8));
        $planId = 'plan-' . bin2hex(random_bytes(8));
        $actor = self::actor($org, 'user-smoke');
        $other = self::actor('foreign-' . $org, 'user-smoke');
        $this->db->beginTransaction();
        try {
            $goal = new GoalSpecification(
                $goalId, $org, 'user-smoke', 'Convert five verified leads',
                [['id' => 'accepted_leads', 'operator' => 'at_least', 'expected' => 5]],
                ['sales.leads.read'],
            );
            $this->goals->createGoal($actor, $goal);
            // A canonical Sales Action can be discovered as a typed federation
            // capability only if the owning live handler, tenant module and
            // manager permission are all available.
            $salesAction = $this->capabilityBindings->requireExecutable($actor, 'sales.create_task');
            self::assert(
                $salesAction->executionBinding === 'action:sales.create_task'
                && $salesAction->ownerDomain === 'sales'
                && $salesAction->sideEffectLevel === 'external'
                && $salesAction->approvalPolicy === 'required'
                && in_array('sales.create_task', $this->capabilityBindings->available($actor), true),
                'Sales Action capability is not backed by live canonical handler/tenant authority.',
            );
            try {
                $this->capabilityBindings->requireExecutable($actor, 'federation.plan.approval');
                throw new \RuntimeException('Internal Federation governance Action offered as Goal capability.');
            } catch (DomainException) {
            }

            $stored = $this->goals->specification($actor, $goalId);
            self::assert($stored !== null
                && $stored->goalId === $goal->goalId
                && $stored->version === $goal->version
                && $stored->desiredResult === $goal->desiredResult
                && ($stored->criteria[0]['id'] ?? '') === 'accepted_leads'
                && ($stored->criteria[0]['expected'] ?? null) === 5,
                'Goal specification did not survive persistence.');
            self::assert($this->goals->specification($other, $goalId) === null,
                'Cross-tenant Goal access allowed.');

            $this->preferences->saveDefaultMode($actor, ExperienceMode::Process);
            $this->preferences->saveWorkspace(
                $actor, 'sales.overview', 'lead:42', ExperienceMode::Expert, ['evidence.details'],
            );
            self::assert($this->preferences->defaultMode($actor) === ExperienceMode::Process,
                'Default Experience mode not durable.');
            $selection = $this->preferences->workspace($actor, 'sales.overview', 'lead:42');
            self::assert($selection['mode'] === ExperienceMode::Expert
                && $selection['expanded_sections'] === ['evidence.details'],
                'Workspace Experience preferences not durable.');
            self::assert($this->preferences->defaultMode($other) === ExperienceMode::Result,
                'Other tenant leaked Experience preferences.');

            foreach ([
                ExperienceMode::Result->value => ['data-experience-mode="result"', 'Ваші бізнес-цілі', 'csrf_token', 'data-federation-runs'],
                ExperienceMode::Process->value => ['data-experience-mode="process"', 'data-goal-process'],
                ExperienceMode::Expert->value => ['data-experience-mode="expert"', 'data-goal-expert'],
            ] as $mode => $markers) {
                $html = $this->twig->render('experience/federation/goals.html.twig', [
                    'mode' => $mode,
                    'goals' => $this->goals->listGoals($actor),
                    'runs' => [],
                    'reconciled' => false,
                    'csrfToken' => 'smoke-csrf-token',
                    'created' => false,
                    'error' => false,
                ]);
                foreach ($markers as $marker) {
                    self::assert(str_contains($html, $marker), 'Goal Workspace SSR missing: ' . $marker);
                }
            }

            // Test fixture inserts a synthetic approved plan to exercise storage only.
            // This command DOES NOT test or bypass production Policy/Approval integration.
            $plan = ['steps' => [
                ['id' => 'read', 'capability_id' => 'sales.leads.read', 'capability_version' => '1.0.0',
                    'side_effect_level' => 'none'],
                ['id' => 'notify', 'capability_id' => 'service.notice.send', 'capability_version' => '1.0.0',
                    'side_effect_level' => 'external'],
            ]];
            $this->db->insert('cos_federation_plans', [
                'organization_id' => $org, 'plan_id' => $planId, 'goal_id' => $goalId,
                'spec_version' => 1, 'plan_version' => 1, 'state' => 'approved',
                'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),
                'created_at' => self::now(),
            ]);
            // Fail closed before Action submission: Federation owns this handler,
            // but is disabled by default for this synthetic organization.
            try {
                $this->approvalCoordinator->requestApproval($actor, $planId, 'fed-smoke-correlation');
                throw new \RuntimeException('Unregistered Federation Action passed activation gate.');
            } catch (DomainException) {
            }
            // A real canonical Action handler can activate a proposed plan,
            // but only with a verified independent human decision and immutable hash.
            // Fixtures are rolled back and do not install/enable the tenant module.
            $activateId = 'plan-' . bin2hex(random_bytes(8));
            $this->db->insert('cos_federation_plans', [
                'organization_id' => $org, 'plan_id' => $activateId, 'goal_id' => $goalId,
                'spec_version' => 1, 'plan_version' => 3, 'state' => 'proposed',
                'plan_json' => json_encode(['steps' => [
                    ['id' => 'checkpoint', 'capability_id' => 'sales.leads.read',
                        'capability_version' => '1.0.0', 'side_effect_level' => 'none'],
                ]], JSON_THROW_ON_ERROR),
                'created_at' => self::now(),
            ]);
            $activationJson = (string) $this->db->fetchOne(
                'SELECT plan_json FROM cos_federation_plans WHERE organization_id = :org AND plan_id = :plan',
                ['org' => $org, 'plan' => $activateId],
            );
            $activationActionId = bin2hex(random_bytes(16));
            $activationParameters = [
                'goal_id' => $goalId, 'plan_id' => $activateId, 'specification_version' => 1,
                'plan_hash' => hash('sha256', $activationJson),
            ];
            $this->db->insert('cos_actions', [
                'id' => $activationActionId, 'organization_id' => $org,
                'type' => FederationPlanApproveHandler::ACTION_TYPE,
                'target_type' => 'cos_federation_plan', 'target_id' => $activateId,
                'parameters' => json_encode($activationParameters, JSON_THROW_ON_ERROR),
                'source_type' => 'USER', 'source_id' => 'user-smoke',
                'status' => 'RUNNING', 'execution_mode' => 'APPROVAL_REQUIRED', 'risk_level' => 'LOW',
                'idempotency_key' => 'activation:' . $activationActionId,
                'correlation_id' => $activationActionId,
            ]);
            $this->db->insert('cos_policy_evaluations', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $activationActionId, 'decision' => 'APPROVAL_REQUIRED',
                'correlation_id' => $activationActionId, 'evaluated_at' => self::now(),
            ]);
            $this->db->insert('cos_approvals', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $activationActionId, 'status' => 'APPROVED',
                'approver_type' => 'USER', 'approver_id' => 'independent-reviewer',
                'requested_by_type' => 'USER', 'requested_by_id' => 'user-smoke',
                'decided_by_type' => 'USER', 'decided_by_id' => 'independent-reviewer',
                'decided_at' => self::now(),
            ]);
            $activationAction = new Action(
                $activationActionId, $org, FederationPlanApproveHandler::ACTION_TYPE,
                'cos_federation_plan', $activateId, $activationParameters,
                'USER', 'user-smoke', 'APPROVAL_REQUIRED', 'LOW',
                'activation:' . $activationActionId, new \DateTimeImmutable(),
                ActionStatus::Running, $activationActionId,
            );
            $activated = $this->approvalHandler->execute($activationAction);
            self::assert($activated->successful
                && $this->db->fetchOne(
                    'SELECT state FROM cos_federation_plans WHERE organization_id = :org AND plan_id = :plan',
                    ['org' => $org, 'plan' => $activateId],
                ) === 'approved', 'Canonical Action handler failed to approve plan.');
            self::assert($this->approvalHandler->execute($activationAction)->successful,
                'Replaying an already-validated internal approval must be idempotent.');
            $forged = new Action(
                bin2hex(random_bytes(16)), $org, FederationPlanApproveHandler::ACTION_TYPE,
                'cos_federation_plan', $activateId, $activationParameters,
                'SYSTEM', 'forged', 'APPROVAL_REQUIRED', 'HIGH', null,
                new \DateTimeImmutable(), ActionStatus::Running,
            );
            self::assert(!$this->approvalHandler->execute($forged)->successful,
                'Federation Action handler accepted forged SYSTEM source.');

            // Independently preflight a read-only, human-gated workflow plan.
            $safePlanId = 'plan-' . bin2hex(random_bytes(8));
            $this->db->insert('cos_federation_plans', [
                'organization_id' => $org, 'plan_id' => $safePlanId, 'goal_id' => $goalId,
                'spec_version' => 1, 'plan_version' => 2, 'state' => 'approved',
                'plan_json' => json_encode(['steps' => [
                    ['id' => 'review', 'capability_id' => 'sales.leads.read', 'capability_version' => '1.0.0',
                        'side_effect_level' => 'none'],
                ]], JSON_THROW_ON_ERROR),
                'created_at' => self::now(),
            ]);
            // Canonical decision records are synthetic and isolated inside this rolled-back
            // transaction. No handler is invoked and the action is never committed to a queue.
            $approvalActionId = bin2hex(random_bytes(16));
            $approvalId = bin2hex(random_bytes(16));
            $planJson = (string) $this->db->fetchOne(
                'SELECT plan_json FROM cos_federation_plans WHERE organization_id = :org AND plan_id = :plan',
                ['org' => $org, 'plan' => $safePlanId],
            );
            $this->db->insert('cos_actions', [
                'id' => $approvalActionId, 'organization_id' => $org,
                'type' => 'cos.federation.plan.approval',
                'target_type' => 'cos_federation_plan', 'target_id' => $safePlanId,
                'parameters' => json_encode([
                    'goal_id' => $goalId, 'plan_id' => $safePlanId,
                    'specification_version' => 1, 'plan_hash' => hash('sha256', $planJson),
                ], JSON_THROW_ON_ERROR),
                'source_type' => 'USER', 'source_id' => 'user-smoke',
                'status' => 'COMPLETED', 'execution_mode' => 'APPROVAL_REQUIRED', 'risk_level' => 'LOW',
                'idempotency_key' => 'test-approval:' . $approvalActionId,
                'correlation_id' => $approvalActionId,
            ]);
            $this->db->insert('cos_policy_evaluations', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $approvalActionId, 'decision' => 'APPROVAL_REQUIRED',
                'correlation_id' => $approvalActionId, 'evaluated_at' => self::now(),
            ]);
            $this->db->insert('cos_approvals', [
                'id' => $approvalId, 'organization_id' => $org, 'action_id' => $approvalActionId,
                'status' => 'APPROVED',
                'approver_type' => 'USER', 'approver_id' => 'second-reviewer',
                'requested_by_type' => 'USER', 'requested_by_id' => 'user-smoke',
                'decided_by_type' => 'USER', 'decided_by_id' => 'second-reviewer',
                'decided_at' => self::now(),
            ]);
            $safeWorkflow = new WorkflowInstance(
                'workflow-test', OrganizationId::fromString($org),
                new Workflow('goal.review', new WorkflowDefinition(
                    'goal.review', '1.0.0', 'Review Goal', 'review',
                    [new HumanStep('review', 'Manager review',
                        new Assignment(AssignmentType::ROLE, 'manager'))],
                )),
            );
            $preflight = $this->workflowPreflight->inspect(
                $actor, $safePlanId, $safeWorkflow, $approvalActionId,
            );
            self::assert($preflight['goal_id'] === $goalId && $preflight['step_count'] === 1,
                'Canonical approved-plan Workflow preflight failed.');
            try {
                $this->workflowPreflight->inspect($actor, $safePlanId, $safeWorkflow, bin2hex(random_bytes(16)));
                throw new \RuntimeException('Workflow preflight accepted absent independent authorization.');
            } catch (DomainException) {
            }
            try {
                $this->workflowPreflight->inspect($other, $safePlanId, $safeWorkflow, $approvalActionId);
                throw new \RuntimeException('Workflow preflight leaked plan across tenants.');
            } catch (DomainException) {
            }

            // Fully run the canonical engine for a decision step that cannot mutate a domain.
            $decisionWorkflow = new WorkflowInstance(
                'decision-workflow', OrganizationId::fromString($org),
                new Workflow('goal.inert.decision', new WorkflowDefinition(
                    'goal.inert.decision', '1.0.0', 'Inert decision', 'review',
                    [new SystemStep('review', 'Read-only checkpoint', 'federation.read_only.checkpoint')],
                )),
            );
            $decisionRunId = 'run-' . bin2hex(random_bytes(8));
            $decisionResult = $this->readOnlyWorkflow->run(
                $actor, $decisionRunId, $safePlanId, $decisionWorkflow, $approvalActionId,
            );
            $persistedDecision = $this->goals->run($actor, $decisionRunId);
            self::assert(
                $decisionResult['run_state'] === 'completed'
                && $persistedDecision !== null && $persistedDecision['state'] === 'completed'
                && (int) $persistedDecision['revision'] === 3
                && $persistedDecision['checkpoint_id'] === $decisionResult['workflow_id']
                && ($persistedDecision['steps'][0]['state'] ?? null) === 'completed',
                'Canonical read-only Workflow failed to persist its Federation checkpoint.',
            );
            self::assert($this->goals->run($other, $decisionRunId) === null,
                'Read-only Workflow result leaked across tenant.');
            try {
                $this->readOnlyWorkflow->run(
                    $actor, 'run-' . bin2hex(random_bytes(8)),
                    $safePlanId, $decisionWorkflow, $approvalActionId,
                );
                throw new \RuntimeException('Read-only Workflow plan replay was accepted.');
            } catch (DomainException) {
            }
            try {
                $this->readOnlyWorkflow->run(
                    $actor, 'run-' . bin2hex(random_bytes(8)),
                    $safePlanId, $safeWorkflow, $approvalActionId,
                );
                throw new \RuntimeException('Human Workflow passed inert-execution gate.');
            } catch (DomainException) {
            }

            // A changed plan or self-approval MUST fail even while plan.state = approved.
            $this->db->executeStatement(
                'UPDATE cos_approvals SET decided_by_id = :user WHERE id = :id AND organization_id = :org',
                ['user' => 'user-smoke', 'id' => $approvalId, 'org' => $org],
            );
            try {
                $this->workflowPreflight->inspect($actor, $safePlanId, $safeWorkflow, $approvalActionId);
                throw new \RuntimeException('Self-approved action accepted by Federation.');
            } catch (DomainException) {
            }
            $this->db->executeStatement(
                'UPDATE cos_approvals SET decided_by_id = :reviewer WHERE id = :id AND organization_id = :org',
                ['reviewer' => 'second-reviewer', 'id' => $approvalId, 'org' => $org],
            );
            $this->db->executeStatement(
                "UPDATE cos_actions SET source_type = 'SYSTEM' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $approvalActionId],
            );
            try {
                $this->workflowPreflight->inspect($actor, $safePlanId, $safeWorkflow, $approvalActionId);
                throw new \RuntimeException('Forged SYSTEM-sourced Goal approval accepted.');
            } catch (DomainException) {
            }
            $this->db->executeStatement(
                "UPDATE cos_actions SET source_type = 'USER' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $approvalActionId],
            );
            $this->db->executeStatement(
                'UPDATE cos_federation_plans SET plan_json = :changed WHERE organization_id = :org AND plan_id = :plan',
                ['changed' => json_encode(['steps' => [['id' => 'changed']]], JSON_THROW_ON_ERROR),
                    'org' => $org, 'plan' => $safePlanId],
            );
            try {
                $this->workflowPreflight->inspect($actor, $safePlanId, $safeWorkflow, $approvalActionId);
                throw new \RuntimeException('Plan changed after canonical approval was accepted.');
            } catch (DomainException) {
            }
            // Separate canonical receipt for the second plan. Every run must prove
            // authorization against its OWN immutable stored plan snapshot.
            $mainActionId = bin2hex(random_bytes(16));
            $mainJson = (string) $this->db->fetchOne(
                'SELECT plan_json FROM cos_federation_plans WHERE organization_id = :org AND plan_id = :plan',
                ['org' => $org, 'plan' => $planId],
            );
            $this->db->insert('cos_actions', [
                'id' => $mainActionId, 'organization_id' => $org,
                'type' => 'cos.federation.plan.approval',
                'target_type' => 'cos_federation_plan', 'target_id' => $planId,
                'parameters' => json_encode([
                    'goal_id' => $goalId, 'plan_id' => $planId,
                    'specification_version' => 1, 'plan_hash' => hash('sha256', $mainJson),
                ], JSON_THROW_ON_ERROR),
                'source_type' => 'USER', 'source_id' => 'user-smoke',
                'status' => 'COMPLETED', 'execution_mode' => 'APPROVAL_REQUIRED', 'risk_level' => 'LOW',
                'idempotency_key' => 'test-approval:' . $mainActionId,
                'correlation_id' => $mainActionId,
            ]);
            $this->db->insert('cos_policy_evaluations', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $mainActionId, 'decision' => 'APPROVAL_REQUIRED',
                'correlation_id' => $mainActionId, 'evaluated_at' => self::now(),
            ]);
            $this->db->insert('cos_approvals', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $mainActionId, 'status' => 'APPROVED',
                'approver_type' => 'USER', 'approver_id' => 'second-reviewer',
                'requested_by_type' => 'USER', 'requested_by_id' => 'user-smoke',
                'decided_by_type' => 'USER', 'decided_by_id' => 'second-reviewer',
                'decided_at' => self::now(),
            ]);
            $this->goals->startApprovedRun($actor, $runId, $planId, $mainActionId);
            try {
                $this->goals->startApprovedRun(
                    $actor, 'run-' . bin2hex(random_bytes(8)), $planId, $mainActionId,
                );
                throw new \RuntimeException('Duplicate federation plan execution was accepted.');
            } catch (DomainException) {
            }

            self::assert(count($this->goals->run($actor, $runId)['steps'] ?? []) === 2,
                'Execution steps not snapshotted.');
            self::assert($this->goals->run($other, $runId) === null, 'Cross-tenant Execution access allowed.');
            $this->goals->transitionRun($actor, $runId, 'pending', 'running', 1);
            try {
                $this->goals->transitionRun($actor, $runId, 'pending', 'running', 1);
                throw new \RuntimeException('Stale concurrent run write accepted.');
            } catch (LogicException) {
            }

            $key = $this->goals->claimStep($actor, $runId, 'read');
            self::assert(strlen($key) === 64, 'Idempotency receipt key missing.');
            try {
                $this->goals->claimStep($actor, $runId, 'read');
                throw new \RuntimeException('Duplicate execution claim accepted.');
            } catch (LogicException) {
            }
            $this->goals->finishStep($actor, $runId, 'read', 'completed', 'sales:lead-read:receipt');
            $this->goals->claimStep($actor, $runId, 'notify');
            try {
                $this->goals->finishStep($actor, $runId, 'notify', 'failed', null);
                throw new \RuntimeException('External side effect improperly marked safe to retry.');
            } catch (DomainException) {
            }
            $this->goals->finishStep($actor, $runId, 'notify', 'ambiguous', null);
            $this->goals->transitionRun($actor, $runId, 'running', 'ambiguous', 2);

            // Unfinished Runs cannot claim an achieved Goal even when a caller
            // knows the target or supplies an invented proof string.
            try {
                $this->goals->recordEvaluation(
                    $actor, 'eval-' . bin2hex(random_bytes(8)), $runId,
                );
                throw new \RuntimeException('Incomplete Run received a Goal evaluation.');
            } catch (DomainException) {
            }
            // Cross-Domain *intent* from a real typed Sales Action, derived only
            // from immutable approved Plan input. No Action is submitted to a
            // worker; a future submission adapter must enforce independent
            // ActionPolicyService APPROVAL_REQUIRED and replay-safe claims.
            $salesGoalId = 'goal-' . bin2hex(random_bytes(8));
            $salesPlanId = 'plan-' . bin2hex(random_bytes(8));
            $salesRunId = 'run-' . bin2hex(random_bytes(8));
            $this->goals->createGoal($actor, new GoalSpecification(
                $salesGoalId, $org, 'user-smoke', 'Create an approved CRM follow-up task',
                [['id' => 'tasks_created', 'operator' => 'at_least', 'expected' => 1]],
                ['sales.create_task'],
            ));
            $approvedInput = [
                'target_type' => 'deal',
                'target_id' => '42',
                'parameters' => ['title' => 'Follow-up approved task', 'due_in_minutes' => 60],
            ];
            $salesProposal = $this->goals->proposePlan($actor, $salesPlanId, $salesGoalId, [[
                'id' => 'sales_task', 'capability_id' => 'sales.create_task',
                'capability_version' => '1.0.0', 'input' => $approvedInput,
            ]]);
            self::assert($salesProposal['status'] === 'proposed',
                'Sales cross-domain plan was not proposed through typed capability catalog.');
            $this->db->executeStatement(
                'UPDATE cos_federation_plans SET state = :state WHERE organization_id = :org AND plan_id = :plan',
                ['state' => 'approved', 'org' => $org, 'plan' => $salesPlanId],
            );
            $salesApprovalActionId = bin2hex(random_bytes(16));
            $salesPlanJson = (string) $this->db->fetchOne(
                'SELECT plan_json FROM cos_federation_plans WHERE organization_id = :org AND plan_id = :plan',
                ['org' => $org, 'plan' => $salesPlanId],
            );
            $this->db->insert('cos_actions', [
                'id' => $salesApprovalActionId, 'organization_id' => $org,
                'type' => 'cos.federation.plan.approval',
                'target_type' => 'cos_federation_plan', 'target_id' => $salesPlanId,
                'parameters' => json_encode([
                    'goal_id' => $salesGoalId, 'plan_id' => $salesPlanId,
                    'specification_version' => 1, 'plan_hash' => hash('sha256', $salesPlanJson),
                ], JSON_THROW_ON_ERROR),
                'source_type' => 'USER', 'source_id' => 'user-smoke',
                'status' => 'COMPLETED', 'execution_mode' => 'APPROVAL_REQUIRED', 'risk_level' => 'LOW',
                'idempotency_key' => 'approved-sales:' . $salesApprovalActionId,
                'correlation_id' => $salesApprovalActionId,
            ]);
            $this->db->insert('cos_policy_evaluations', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $salesApprovalActionId, 'decision' => 'APPROVAL_REQUIRED',
                'correlation_id' => $salesApprovalActionId, 'evaluated_at' => self::now(),
            ]);
            $this->db->insert('cos_approvals', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $salesApprovalActionId, 'status' => 'APPROVED',
                'approver_type' => 'USER', 'approver_id' => 'second-reviewer',
                'requested_by_type' => 'USER', 'requested_by_id' => 'user-smoke',
                'decided_by_type' => 'USER', 'decided_by_id' => 'second-reviewer',
                'decided_at' => self::now(),
            ]);
            $this->goals->startApprovedRun($actor, $salesRunId, $salesPlanId, $salesApprovalActionId);
            $this->goals->transitionRun($actor, $salesRunId, 'pending', 'running', 1);
            $intent = $this->actionIntents->create(
                $actor, $salesRunId, 'sales_task', $salesApprovalActionId,
            );
            self::assert(
                $intent->type === 'sales.create_task'
                && $intent->targetType === 'deal'
                && $intent->targetId === '42'
                && $intent->parameters === $approvedInput['parameters']
                && $intent->executionMode === 'APPROVAL_REQUIRED'
                && $intent->idempotencyKey === 'fed:' . ($this->goals->run($actor, $salesRunId)['steps'][0]['idempotency_key'] ?? ''),
                'Federation intent was not derived from approved immutable Action snapshot.',
            );
            self::assert($this->db->fetchOne(
                'SELECT COUNT(*) FROM cos_actions WHERE organization_id = :org AND type = :type',
                ['org' => $org, 'type' => 'sales.create_task'],
            ) == 0, 'Federation intent factory unexpectedly dispatched a real CRM Action.');
            // Worker admission: two independently approved canonical Actions,
            // immutable Sales input and no prior uncertain attempt are mandatory.
            $this->goals->claimStep($actor, $salesRunId, 'sales_task');
            try {
                $this->runFinalizer->finalize($actor, $salesRunId, 2);
                throw new \RuntimeException('Federation Run finalized before its external Step completed.');
            } catch (DomainException) {
            }
            $salesActionId = bin2hex(random_bytes(16));
            $this->db->insert('cos_actions', [
                'id' => $salesActionId, 'organization_id' => $org,
                'type' => 'sales.create_task',
                'target_type' => 'deal', 'target_id' => '42',
                'parameters' => json_encode($approvedInput['parameters'], JSON_THROW_ON_ERROR),
                'source_type' => 'USER', 'source_id' => 'user-smoke',
                'status' => 'QUEUED', 'execution_mode' => 'APPROVAL_REQUIRED',
                'risk_level' => 'HIGH', 'idempotency_key' => $intent->idempotencyKey,
                'correlation_id' => $salesActionId,
            ]);
            $this->db->insert('cos_policy_evaluations', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $salesActionId, 'decision' => 'APPROVAL_REQUIRED',
                'correlation_id' => $salesActionId, 'evaluated_at' => self::now(),
            ]);
            $salesReviewId = bin2hex(random_bytes(16));
            $this->db->insert('cos_approvals', [
                'id' => $salesReviewId, 'organization_id' => $org,
                'action_id' => $salesActionId, 'status' => 'APPROVED',
                'approver_type' => 'USER', 'approver_id' => 'external-reviewer',
                'requested_by_type' => 'USER', 'requested_by_id' => 'user-smoke',
                'decided_by_type' => 'USER', 'decided_by_id' => 'external-reviewer',
                'decided_at' => self::now(),
            ]);
            $salesAction = new Action(
                $salesActionId, $org, 'sales.create_task', 'deal', '42',
                $approvedInput['parameters'], 'USER', 'user-smoke', 'APPROVAL_REQUIRED',
                'HIGH', $intent->idempotencyKey, new \DateTimeImmutable(),
                ActionStatus::Queued, $salesActionId,
            );
            $this->actionAdmission->assertAuthorized($salesAction);
            $this->db->executeStatement(
                'UPDATE cos_approvals SET decided_by_id = :user
                 WHERE organization_id = :org AND id = :approval',
                ['user' => 'user-smoke', 'org' => $org, 'approval' => $salesReviewId],
            );
            try {
                $this->actionAdmission->assertAuthorized($salesAction);
                throw new \RuntimeException('Self-approved external Federation Action passed worker admission.');
            } catch (DomainException) {
            }
            $this->db->executeStatement(
                'UPDATE cos_approvals SET decided_by_id = :reviewer
                 WHERE organization_id = :org AND id = :approval',
                ['reviewer' => 'external-reviewer', 'org' => $org, 'approval' => $salesReviewId],
            );
            $this->actionAdmission->assertAuthorized($salesAction);
            // Two attempts imply an uncertain prior external effect. No replay.
            foreach ([1, 2] as $attempt) {
                $this->db->insert('cos_action_attempts', [
                    'action_id' => $salesActionId, 'organization_id' => $org,
                    'attempt' => $attempt, 'worker_id' => 'smoke-worker',
                    'status' => 'FAILED', 'started_at' => self::now(),
                ]);
            }
            try {
                $this->actionAdmission->assertAuthorized($salesAction);
                throw new \RuntimeException('Stale external Federation Action was re-executed.');
            } catch (DomainException) {
            }
            $this->db->executeStatement(
                'UPDATE cos_federation_plans SET plan_json = :changed WHERE organization_id = :org AND plan_id = :plan',
                ['changed' => json_encode(['steps' => []], JSON_THROW_ON_ERROR),
                    'org' => $org, 'plan' => $salesPlanId],
            );
            try {
                $this->actionIntents->create(
                    $actor, $salesRunId, 'sales_task', $salesApprovalActionId,
                );
                throw new \RuntimeException('Modified approved Sales Action snapshot produced a federated intent.');
            } catch (DomainException) {
            }

            $this->db->executeStatement(
                'UPDATE cos_federation_plans SET plan_json = :approved WHERE organization_id = :org AND plan_id = :plan',
                ['approved' => $salesPlanJson, 'org' => $org, 'plan' => $salesPlanId],
            );
            // Reconciliation never trusts COMPLETED by itself. An Action with
            // multiple attempts remains unresolved, not auto-marked complete.
            $this->db->executeStatement(
                "UPDATE cos_actions SET status = 'COMPLETED' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $salesActionId],
            );
            $uncertain = $this->receiptReconciler->reconcile($actor, $salesRunId, 'sales_task');
            self::assert($uncertain['status'] === 'manual_reconciliation_required'
                && ($this->goals->run($actor, $salesRunId)['steps'][0]['state'] ?? null) === 'claimed',
                'Uncertain multi-attempt Action was falsely marked successful.');
            $this->db->executeStatement(
                'DELETE FROM cos_action_attempts WHERE organization_id = :org AND action_id = :id',
                ['org' => $org, 'id' => $salesActionId],
            );
            $this->db->insert('cos_action_attempts', [
                'action_id' => $salesActionId, 'organization_id' => $org,
                'attempt' => 1, 'worker_id' => 'smoke-worker',
                'status' => 'COMPLETED', 'started_at' => self::now(), 'finished_at' => self::now(),
            ]);
            // A forged completed Action with a mismatched target must never
            // establish a verified Domain receipt, even with one successful attempt.
            $this->db->executeStatement(
                "UPDATE cos_actions SET target_id = 'unapproved-deal' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $salesActionId],
            );
            $forged = $this->receiptReconciler->reconcile($actor, $salesRunId, 'sales_task');
            self::assert($forged['status'] === 'manual_reconciliation_required'
                && ($this->goals->run($actor, $salesRunId)['steps'][0]['state'] ?? null) === 'claimed',
                'Unapproved Action target was incorrectly accepted as a completed receipt.');
            $this->db->executeStatement(
                "UPDATE cos_actions SET target_id = '42' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $salesActionId],
            );
            $verified = $this->receiptReconciler->reconcile($actor, $salesRunId, 'sales_task');
            self::assert($verified['status'] === 'completed'
                && $verified['action_id'] === $salesActionId
                && ($this->goals->run($actor, $salesRunId)['steps'][0]['state'] ?? null) === 'completed',
                'Verified single-attempt Action receipt was not durably reconciled.');
            self::assert($this->receiptReconciler->reconcile($actor, $salesRunId, 'sales_task')['status'] === 'completed',
                'Completed Action receipt reconciliation is not idempotent.');
            $finished = $this->runFinalizer->finalize($actor, $salesRunId, 2);
            self::assert($finished['state'] === 'completed'
                && $finished['steps'] === 1
                && $finished['revision'] === 3
                && ($this->goals->run($actor, $salesRunId)['state'] ?? null) === 'completed',
                'Cross-domain Federation Run did not finalize on attested Action receipt.');
            // Action completion is NOT a CRM outcome metric. Unsupported
            // tasks_created must remain unverifiable, not fabricated success.
            $unknown = $this->goals->recordEvaluation(
                $actor, 'eval-' . bin2hex(random_bytes(8)), $salesRunId,
            );
            self::assert($unknown['result'] === 'unverifiable'
                && array_key_exists('observed', $unknown['criteria'][0])
                && $unknown['criteria'][0]['observed'] === null
                && $unknown['run_id'] === $salesRunId
                && $unknown['evidence_policy'] === 'domain_read_model_v1',
                'A completed Action was misrepresented as a business outcome.');
            $latest = $this->goals->latestTrustedEvaluation($actor, $salesRunId);
            self::assert($latest !== null
                && $latest['result'] === 'unverifiable'
                && $latest['run_id'] === $salesRunId
                && is_string($latest['evaluation_id'] ?? null)
                && $this->goals->latestTrustedEvaluation($other, $salesRunId) === null
                && $this->goals->latestTrustedEvaluation($actor, $runId) === null,
                'Latest trusted evaluation leaked between tenants or incomplete executions.');

            // Pre-cutover ad hoc evaluation must never replace trusted data,
            // even when it forges this run ID and claims a successful result.
            $this->db->insert('cos_federation_evaluations', [
                'organization_id' => $org,
                'evaluation_id' => 'eval-' . bin2hex(random_bytes(8)),
                'goal_id' => $salesGoalId, 'spec_version' => 1,
                'result' => 'satisfied',
                'evaluation_json' => json_encode([
                    'run_id' => $salesRunId, 'result' => 'satisfied',
                    'criteria' => [['criterion_id' => 'tasks_created', 'observed' => 100,
                        'expected' => 1, 'result' => 'satisfied', 'evidence' => ['forged']]],
                ], JSON_THROW_ON_ERROR),
                'evaluated_at' => self::now(),
            ]);
            self::assert($this->goals->latestTrustedEvaluation($actor, $salesRunId)['result'] === 'unverifiable',
                'Legacy user-supplied Goal evidence displaced canonical Domain evaluation.');

            $previewOutcome = [
                'result' => 'partial',
                'recorded_at' => '2026-10-09 00:00:00.000000',
                'evaluation_id' => 'eval-visible-test',
                'evidence_policy' => 'domain_read_model_v1',
                'specification_version' => 1,
                'criteria' => [[
                    'criterion_id' => 'sales.won_deals', 'observed' => 2,
                    'expected' => 5, 'result' => 'partial',
                    'source' => 'sales.cos_events.closed_outcomes.v1',
                    'window_start' => '2026-10-01T00:00:00+00:00',
                    'window_end' => '2026-10-09T00:00:00+00:00',
                    'evidence' => ['trusted-evidence-private:<script>'],
                ]],
            ];
            foreach (['result', 'process', 'expert'] as $displayMode) {
                $view = $this->twig->render('experience/federation/goals.html.twig', [
                    'mode' => $displayMode, 'goals' => [],
                    'runs' => [[
                        'run_id' => $salesRunId, 'goal_id' => $salesGoalId,
                        'plan_id' => $salesPlanId, 'state' => 'completed',
                        'revision' => 3, 'recovery' => null, 'outcome' => $previewOutcome,
                    ]],
                    'csrfToken' => 'smoke-csrf-token',
                    'created' => false, 'error' => false,
                    'reconciled' => false, 'evaluated' => false, 'outcomeError' => false,
                ]);
                self::assert(str_contains($view, 'data-goal-outcome-result="partial"')
                    && str_contains($view, 'Частково досягнуто')
                    && str_contains($view, '/workspace/goals/runs/' . $salesRunId . '/evaluate')
                    && str_contains($view, 'smoke-csrf-token'),
                    'Outcome summary or CSRF-guarded refresh missing in ' . $displayMode . ' view.');
                self::assert(str_contains($view, 'data-goal-outcome-process') === ($displayMode !== 'result')
                    && str_contains($view, 'sales.cos_events.closed_outcomes.v1') === ($displayMode !== 'result')
                    && str_contains($view, 'trusted-evidence-private:') === ($displayMode === 'expert')
                    && str_contains($view, 'data-goal-outcome-expert') === ($displayMode === 'expert')
                    && !str_contains($view, '<script>'),
                    'Outcome evidence disclosure violated Result/Process/Expert boundaries.');
            }
            try {
                $this->goals->recordEvaluation(
                    $other, 'eval-' . bin2hex(random_bytes(8)), $salesRunId,
                );
                throw new \RuntimeException('Foreign tenant evaluated a Goal.');
            } catch (DomainException) {
            }
            try {
                $this->runFinalizer->finalize($actor, $salesRunId, 2);
                throw new \RuntimeException('Terminal Federation Run was finalized twice.');
            } catch (LogicException) {
            }
            try {
                $this->runFinalizer->finalize($other, $salesRunId, 2);
                throw new \RuntimeException('Foreign tenant finalized Federation Run.');
            } catch (LogicException) {
            }

            try {
                $this->receiptReconciler->reconcile($other, $salesRunId, 'sales_task');
                throw new \RuntimeException('Foreign tenant reconciled an external Action.');
            } catch (DomainException) {
            }

            // End-to-end deterministic two-step orchestration via live
            // ActionPolicyService. Transaction fixture never reaches real CRM
            // handlers; canonical worker completion is represented by receipts.
            $linearGoal = 'goal-' . bin2hex(random_bytes(8));
            $linearPlan = 'plan-' . bin2hex(random_bytes(8));
            $linearRun = 'run-' . bin2hex(random_bytes(8));
            $this->goals->createGoal($actor, new GoalSpecification(
                $linearGoal, $org, 'user-smoke', 'Create two approved follow-up tasks in order',
                [
                    ['id' => 'sales.local_tasks_created', 'operator' => 'at_least', 'expected' => 2],
                    ['id' => 'growth.inbound_responses_recorded', 'operator' => 'at_least', 'expected' => 1],
                    ['id' => 'growth.run_linked_inbound_responses', 'operator' => 'at_least', 'expected' => 1],
                    ['id' => 'capital_markets.research_results_validated', 'operator' => 'at_least', 'expected' => 1],
                    ['id' => 'documents.signatures_recorded', 'operator' => 'at_least', 'expected' => 1],
                    ['id' => 'capital_markets.run_linked_validated_results', 'operator' => 'at_least', 'expected' => 1],
                    ['id' => 'documents.run_linked_signatures', 'operator' => 'at_least', 'expected' => 1],
                ],
                ['sales.create_task'],
            ));
            $this->goals->proposePlan($actor, $linearPlan, $linearGoal, [
                ['id' => 'z_first', 'capability_id' => 'sales.create_task',
                    'capability_version' => '1.0.0',
                    'input' => ['target_type' => 'deal', 'target_id' => '42',
                        'parameters' => ['title' => 'First approved task']]],
                ['id' => 'a_second', 'capability_id' => 'sales.create_task',
                    'capability_version' => '1.0.0',
                    'input' => ['target_type' => 'deal', 'target_id' => '43',
                        'parameters' => ['title' => 'Second approved task']]],
            ]);
            try {
                $this->sequentialOrchestrator->start($actor, $linearRun, $linearPlan, $salesApprovalActionId);
                throw new \RuntimeException('Disabled Federation tenant started a real orchestrated Run.');
            } catch (DomainException) {
            }
            // An organization must explicitly opt into Federation.
            $this->db->insert('cos_organization_modules', [
                'organization_id' => $org, 'module_id' => 'federation', 'enabled' => 1,
            ]);
            $linearJson = (string) $this->db->fetchOne(
                'SELECT plan_json FROM cos_federation_plans WHERE organization_id = :org AND plan_id = :plan',
                ['org' => $org, 'plan' => $linearPlan],
            );
            $linearApprovalId = bin2hex(random_bytes(16));
            $linearApprovalParams = [
                'goal_id' => $linearGoal, 'plan_id' => $linearPlan,
                'specification_version' => 1, 'plan_hash' => hash('sha256', $linearJson),
            ];
            $this->db->insert('cos_actions', [
                'id' => $linearApprovalId, 'organization_id' => $org,
                'type' => FederationPlanApproveHandler::ACTION_TYPE,
                'target_type' => 'cos_federation_plan', 'target_id' => $linearPlan,
                'parameters' => json_encode($linearApprovalParams, JSON_THROW_ON_ERROR),
                'source_type' => 'USER', 'source_id' => 'user-smoke',
                'status' => 'RUNNING', 'execution_mode' => 'APPROVAL_REQUIRED',
                'risk_level' => 'LOW', 'idempotency_key' => 'linear:' . $linearApprovalId,
                'correlation_id' => $linearApprovalId,
            ]);
            $this->db->insert('cos_policy_evaluations', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $linearApprovalId, 'decision' => 'APPROVAL_REQUIRED',
                'correlation_id' => $linearApprovalId, 'evaluated_at' => self::now(),
            ]);
            $this->db->insert('cos_approvals', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $linearApprovalId, 'status' => 'APPROVED',
                'approver_type' => 'USER', 'approver_id' => 'independent-reviewer',
                'requested_by_type' => 'USER', 'requested_by_id' => 'user-smoke',
                'decided_by_type' => 'USER', 'decided_by_id' => 'independent-reviewer',
                'decided_at' => self::now(),
            ]);
            $linearAction = new Action(
                $linearApprovalId, $org, FederationPlanApproveHandler::ACTION_TYPE,
                'cos_federation_plan', $linearPlan, $linearApprovalParams,
                'USER', 'user-smoke', 'APPROVAL_REQUIRED', 'LOW',
                'linear:' . $linearApprovalId, new \DateTimeImmutable(),
                ActionStatus::Running, $linearApprovalId,
            );
            self::assert($this->approvalHandler->execute($linearAction)->successful,
                'Linear Federation Plan did not activate through canonical Action handler.');
            $this->db->executeStatement(
                "UPDATE cos_actions SET status = 'COMPLETED' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $linearApprovalId],
            );
            $this->db->insert('cos_policies', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'code' => 'linear-sales-review', 'name' => 'Linear federation human review',
                'action_type' => 'sales.create_task', 'conditions' => '[]',
                'decision' => 'APPROVAL_REQUIRED', 'priority' => 10, 'status' => 'ACTIVE',
            ]);
            self::assert($this->sequentialOrchestrator->start(
                $actor, $linearRun, $linearPlan, $linearApprovalId,
            )['state'] === 'pending', 'Linear Federation Run was not durably created.');
            self::assert(in_array($linearRun,
                array_column($this->goals->listRuns($actor), 'run_id'), true)
                && !in_array($linearRun,
                    array_column($this->goals->listRuns($other), 'run_id'), true),
                'Tenant-scoped adaptive Run list leaked or lost an execution.');
            self::assert($this->sequentialOrchestrator->advance(
                $actor, $linearRun, $linearApprovalId,
            )['state'] === 'running', 'Linear Federation Run did not start.');
            // A direct persistence caller cannot jump past the first
            // approved Step, even if it bypasses the API orchestrator.
            try {
                $this->goals->claimStep($actor, $linearRun, 'a_second');
                throw new \RuntimeException('Direct Step claim skipped an uncompleted DAG predecessor.');
            } catch (DomainException) {
            }
            $firstAction = $this->sequentialOrchestrator->advance($actor, $linearRun, $linearApprovalId);
            self::assert($firstAction['state'] === 'awaiting_human_approval'
                && $firstAction['step_id'] === 'z_first',
                'Federation order must follow approved Plan, not alphabetic Step IDs.');
            self::assert($this->sequentialOrchestrator->advance(
                $actor, $linearRun, $linearApprovalId,
            )['state'] === 'awaiting_action', 'Federation Action awaiting human approval was replayed.');
            try {
                $this->goals->claimStep($actor, $linearRun, 'a_second');
                throw new \RuntimeException('Direct Step claim overlapped an active external Action.');
            } catch (DomainException) {
            }
            self::assert((int) $this->db->fetchOne(
                "SELECT attempts FROM cos_federation_steps
                 WHERE organization_id = :org AND run_id = :run AND step_id = 'a_second'",
                ['org' => $org, 'run' => $linearRun],
            ) === 0, 'Denied competing claim still incremented attempts.');
            self::assert((int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM cos_actions WHERE organization_id = :org AND type = 'sales.create_task'",
                ['org' => $org],
            ) === 2, 'Federation scheduled unapproved subsequent Action.');
            $completeActionFixture = function (string $actionId) use ($org): void {
                $this->db->executeStatement(
                    "UPDATE cos_approvals SET status = 'APPROVED',
                        decided_by_type = 'USER', decided_by_id = 'independent-reviewer',
                        decided_at = NOW(6) WHERE organization_id = :org AND action_id = :id",
                    ['org' => $org, 'id' => $actionId],
                );
                $this->db->executeStatement(
                    "UPDATE cos_actions SET status = 'COMPLETED'
                     WHERE organization_id = :org AND id = :id",
                    ['org' => $org, 'id' => $actionId],
                );
                $this->db->insert('cos_action_attempts', [
                    'action_id' => $actionId, 'organization_id' => $org,
                    'attempt' => 1, 'worker_id' => 'smoke-worker',
                    'status' => 'COMPLETED', 'started_at' => self::now(),
                    'finished_at' => self::now(),
                ]);
            };
            $health = $this->recovery->inspect($actor, $linearRun);
            self::assert($health['attention_count'] === 0
                && $health['recoverable_count'] === 0
                && ($health['steps'][1]['recovery_state'] ?? null) === 'awaiting_human_approval',
                'Pending Federation Action was misclassified as a recovery incident.');
            $runView = [
                'run_id' => $linearRun, 'goal_id' => $linearGoal,
                'plan_id' => $linearPlan, 'state' => 'running',
                'revision' => 2, 'recovery' => $health,
            ];
            foreach ([
                'result' => [false, false],
                'process' => [true, false],
                'expert' => [true, true],
            ] as $mode => [$showSteps, $showActionId]) {
                $html = $this->twig->render('experience/federation/goals.html.twig', [
                    'mode' => $mode, 'runs' => [$runView],
                    'goals' => $this->goals->listGoals($actor),
                    'csrfToken' => 'smoke-csrf-token',
                    'created' => false, 'error' => false, 'reconciled' => false,
                ]);
                self::assert(str_contains($html, 'data-run-id="' . $linearRun . '"')
                    && str_contains($html, 'data-federation-runs')
                    && str_contains($html, 'Потребують уваги:'),
                    'Adaptive Federation recovery list failed to render.');
                self::assert(str_contains($html, 'data-goal-recovery-process') === $showSteps,
                    'Recovery Process details leaked into another Experience mode.');
                self::assert(str_contains($html, 'data-goal-recovery-expert') === $showActionId,
                    'Recovery Action identifier visibility ignores Expert mode.');
            }
            $this->db->executeStatement(
                "UPDATE cos_actions SET status = 'REJECTED' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $firstAction['action_id']],
            );
            self::assert($this->sequentialOrchestrator->advance(
                $actor, $linearRun, $linearApprovalId,
            )['state'] === 'manual_reconciliation_required',
                'Rejected external Action was treated as a healthy waiting worker.');
            $this->db->executeStatement(
                "UPDATE cos_actions SET status = 'PENDING_APPROVAL' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $firstAction['action_id']],
            );
            $completeActionFixture((string) $firstAction['action_id']);
            self::assert($this->sequentialOrchestrator->advance(
                $actor, $linearRun, $linearApprovalId,
            )['state'] === 'step_completed', 'First externally approved Step was not reconciled.');
            // Tampering a completed predecessor must block the next external
            // Action. The receipt is independently checked at every tick.
            $this->db->executeStatement(
                "UPDATE cos_actions SET target_id = 'forged' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $firstAction['action_id']],
            );
            self::assert($this->sequentialOrchestrator->advance(
                $actor, $linearRun, $linearApprovalId,
            )['state'] === 'manual_reconciliation_required',
                'Forged predecessor receipt authorized a dependent Action.');
            self::assert($this->recovery->inspect($actor, $linearRun)['attention_count'] === 1,
                'Recovery Inspector failed to flag tampered predecessor evidence.');
            $this->db->executeStatement(
                "UPDATE cos_actions SET target_id = '42' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $firstAction['action_id']],
            );
            $secondAction = $this->sequentialOrchestrator->advance($actor, $linearRun, $linearApprovalId);
            self::assert($secondAction['state'] === 'awaiting_human_approval'
                && $secondAction['step_id'] === 'a_second'
                && $secondAction['action_id'] !== $firstAction['action_id'],
                'Second sequential Action did not create an independent approval gate.');
            // Verify the actual worker-side DAG gate, not only the API
            // orchestrator. Approval is independent and existing canonical
            // Policy evidence remains mandatory.
            $this->db->executeStatement(
                "UPDATE cos_approvals SET status = 'APPROVED',
                    decided_by_type = 'USER', decided_by_id = 'independent-reviewer',
                    decided_at = NOW(6) WHERE organization_id = :org AND action_id = :id",
                ['org' => $org, 'id' => $secondAction['action_id']],
            );
            $pendingWorker = $this->db->fetchAssociative(
                'SELECT id, type, target_type, target_id, parameters, source_type, source_id,
                        execution_mode, risk_level, idempotency_key, correlation_id
                 FROM cos_actions WHERE organization_id = :org AND id = :id',
                ['org' => $org, 'id' => $secondAction['action_id']],
            );
            self::assert(is_array($pendingWorker), 'Second canonical Action vanished before worker admission.');
            $workerAction = new Action(
                (string) $pendingWorker['id'], $org, (string) $pendingWorker['type'],
                $pendingWorker['target_type'] !== null ? (string) $pendingWorker['target_type'] : null,
                $pendingWorker['target_id'] !== null ? (string) $pendingWorker['target_id'] : null,
                json_decode((string) $pendingWorker['parameters'], true, 512, JSON_THROW_ON_ERROR),
                (string) $pendingWorker['source_type'], (string) $pendingWorker['source_id'],
                (string) $pendingWorker['execution_mode'], (string) $pendingWorker['risk_level'],
                (string) $pendingWorker['idempotency_key'], new \DateTimeImmutable(),
                ActionStatus::Queued, (string) $pendingWorker['correlation_id'],
            );
            $this->actionAdmission->assertAuthorized($workerAction);
            $this->db->executeStatement(
                "UPDATE cos_actions SET target_id = 'forged' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $firstAction['action_id']],
            );
            try {
                $this->actionAdmission->assertAuthorized($workerAction);
                throw new \RuntimeException('Worker accepted a corrupted predecessor Action receipt.');
            } catch (DomainException) {
            }
            $this->db->executeStatement(
                "UPDATE cos_actions SET target_id = '42' WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => $firstAction['action_id']],
            );
            $this->actionAdmission->assertAuthorized($workerAction);
            $completeActionFixture((string) $secondAction['action_id']);
            $pendingRecovery = $this->recovery->inspect($actor, $linearRun);
            self::assert($pendingRecovery['recoverable_count'] === 1
                && $pendingRecovery['attention_count'] === 0,
                'Completed Federation Action was not eligible for receipt-only reconciliation.');
            $recoveryHtml = $this->twig->render('experience/federation/goals.html.twig', [
                'mode' => 'process',
                'runs' => [[
                    'run_id' => $linearRun, 'goal_id' => $linearGoal,
                    'plan_id' => $linearPlan, 'state' => 'running', 'revision' => 2,
                    'recovery' => $pendingRecovery,
                ]],
                'goals' => $this->goals->listGoals($actor),
                'csrfToken' => 'smoke-csrf-token',
                'created' => false, 'error' => false, 'reconciled' => false,
            ]);
            self::assert(str_contains($recoveryHtml, 'Звірити завершені дії без повторного запуску')
                && str_contains($recoveryHtml, '/workspace/goals/runs/' . $linearRun . '/reconcile')
                && str_contains($recoveryHtml, 'smoke-csrf-token'),
                'Recovery action is not accessible through CSRF-protected adaptive Workspace.');
            self::assert($this->recovery->reconcileVerified($actor, $linearRun)['reconciled'] === 1,
                'Federation recovery did not apply the independently verified Action receipt.');
            self::assert($this->recovery->reconcileVerified($actor, $linearRun)['reconciled'] === 0,
                'Receipt-only recovery was not idempotent.');
            self::assert($this->recovery->inspect($actor, $linearRun)['recoverable_count'] === 0,
                'Reconciled Federation Action remains eligible for duplicate recovery.');
            try {
                $this->recovery->inspect($other, $linearRun);
                throw new \RuntimeException('Foreign tenant inspected Federation recovery.');
            } catch (DomainException) {
            }
            self::assert($this->sequentialOrchestrator->advance(
                $actor, $linearRun, $linearApprovalId,
            )['state'] === 'completed', 'Multi-step Federation Run was not finalized.');
            self::assert($this->sequentialOrchestrator->advance(
                $actor, $linearRun, $linearApprovalId,
            )['state'] === 'completed', 'Terminal linear Run caused duplicate execution.');

            // Test runtime uses Action receipts only: no actual CRM task rows
            // are written. A real Domain reader must therefore return zero,
            // never upgrade 2 completed Actions to 2 completed business facts.
            $local = $this->goals->recordEvaluation(
                $actor, 'eval-' . bin2hex(random_bytes(8)), $linearRun,
            );
            self::assert($local['result'] === 'unverifiable'
                && $local['criteria'][0]['result'] === 'unsatisfied'
                && $local['criteria'][0]['observed'] === 0
                && $local['criteria'][0]['source'] === 'sales.tn_client_case_activities.task.v1'
                && count($local['criteria'][0]['evidence']) === 1
                && $local['criteria'][1]['result'] === 'unverifiable'
                && $local['criteria'][2]['result'] === 'unverifiable'
                && $local['criteria'][3]['result'] === 'unverifiable'
                && $local['criteria'][4]['observed'] === 0
                && $local['criteria'][5]['result'] === 'unverifiable'
                && $local['criteria'][6]['result'] === 'unverifiable',
                'Domain evidence from disabled Growth/Research modules was fabricated or local Action receipts were counted.');
            self::assert($this->goals->latestTrustedEvaluation($actor, $linearRun)['result'] === 'unverifiable'
                && $this->goals->latestTrustedEvaluation($other, $linearRun) === null,
                'Initial multi-domain evaluation leaked disabled modules or tenant data.');

            // Activate only the two optional business Domains for the test tenant.
            // Platform Documents is a core capability, with no module toggle.
            $this->db->insert('cos_organization_modules', [
                'organization_id' => $org, 'module_id' => 'growth', 'enabled' => 1,
            ]);
            $this->db->insert('cos_organization_modules', [
                'organization_id' => $org, 'module_id' => 'capital_markets', 'enabled' => 1,
            ]);

            // Authoritative persisted facts AFTER Run creation, not Action
            // responses. All writes belong to the outer rollback transaction.
            $eventTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s.u');
            $oldTime = '2020-01-01 00:00:00.000000';
            $foreignOrg = 'foreign-' . $org;
            $newId = static fn (): string => bin2hex(random_bytes(10));

            // Growth unique inbound response; older/out-of-tenant facts excluded.
            foreach ([
                [$org, $eventTime, (string) $firstAction['action_id']],
                [$org, $oldTime, (string) $firstAction['action_id']],
                [$foreignOrg, $eventTime, (string) $firstAction['action_id']],
                [$org, $eventTime, $newId()],
            ] as [$tenantId, $time, $originActionId]) {
                $id = $newId();
                $this->db->insert('tn_growth_engagement_responses', [
                    'organization_id' => $tenantId, 'response_id' => $id,
                    'source_event_id' => 'event-' . $id, 'execution_id' => 'exec-' . $id,
                    'candidate_id' => 'candidate-' . $id,
                    'recommendation_id' => 'recommend-' . $id,
                    'action_id' => $originActionId, 'channel' => 'email',
                    'body' => 'Confirmed fixture response',
                    'body_hash' => hash('sha256', 'Confirmed fixture response'),
                    'occurred_at' => $time, 'created_at' => $time,
                ]);
            }

            // Research: only persisted VALIDATED results count.
            $researchOriginFixture = '';
            foreach ([
                [$org, $eventTime, 'VALIDATED'],
                [$org, $eventTime, 'REJECTED'],
                [$org, $oldTime, 'VALIDATED'],
                [$foreignOrg, $eventTime, 'VALIDATED'],
            ] as [$tenantId, $time, $status]) {
                $id = $newId();
                if ($tenantId === $org && $time === $eventTime && $status === 'VALIDATED') {
                    $researchOriginFixture = 'result-' . $id;
                }
                $this->db->insert('tn_capital_market_research_results', [
                    'organization_id' => $tenantId,
                    'result_id' => 'result-' . $id,
                    'experiment_id' => 'experiment-' . $id,
                    'status' => $status,
                    'record_json' => json_encode(['fixture' => true, 'status' => $status,
                        'result_id' => 'result-' . $id], JSON_THROW_ON_ERROR),
                    'created_at' => $time,
                ]);
            }

            // Documents: signed + nonempty reference + actor. Requested,
            // unreferenced, old or foreign signatures must not count.
            $documentOriginFixture = '';
            foreach ([
                [$org, $eventTime, 'signed', 'signed-proof'],
                [$org, $eventTime, 'requested', null],
                [$org, $eventTime, 'signed', ''],
                [$org, $oldTime, 'signed', 'signed-proof'],
                [$foreignOrg, $eventTime, 'signed', 'signed-proof'],
            ] as [$tenantId, $time, $status, $signatureRef]) {
                $id = $newId();
                if ($tenantId === $org && $time === $eventTime
                    && $status === 'signed' && $signatureRef === 'signed-proof') {
                    $documentOriginFixture = 'signature-' . $id;
                }
                $this->db->insert('cos_document_signatures', [
                    'organization_id' => $tenantId,
                    'signature_id' => 'signature-' . $id,
                    'document_id' => 'document-' . $id,
                    'signer_id' => 'user-fixture',
                    'status' => $status, 'requested_by' => 1,
                    'requested_at' => $time,
                    'signed_by' => $status === 'signed' ? 'user-fixture' : null,
                    'signed_by_actor_id' => $status === 'signed' ? 1 : null,
                    'signature_reference' => $signatureRef,
                    'signed_at' => $status === 'signed' ? $time : null,
                ]);
            }

            // Deliberately forged origin journal rows have perfectly valid
            // native source fingerprints but reference the Sales Action of
            // this Run. They must NEVER become Research/Document attribution.
            foreach ([
                ['capital_markets', $researchOriginFixture],
                ['documents', $documentOriginFixture],
            ] as [$domain, $outcomeId]) {
                $source = $this->originRecorder->nativeOutcome($org, $domain, $outcomeId);
                self::assert($source !== null,
                    'Native Domain source unavailable for origin trust-boundary smoke.');
                $this->db->insert('cos_federation_outcome_origins', [
                    'organization_id' => $org, 'domain_id' => $domain,
                    'outcome_id' => $outcomeId, 'run_id' => $linearRun,
                    'step_id' => 'z_first', 'action_id' => $firstAction['action_id'],
                    'source_fingerprint' => FederationOutcomeOriginRecorder::fingerprint(
                        $org, $domain, $outcomeId, $source,
                    ),
                    'linked_at' => $eventTime,
                ]);
                try {
                    $this->originReader->verifiedForRun(
                        $actor, $linearRun, $domain,
                        new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('UTC')),
                        new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC')),
                    );
                    throw new \RuntimeException('A Sales Action was forged as Research/Documents outcome origin.');
                } catch (DomainException) {
                    // Explicit integrity failure, never zero attributed outcomes.
                }
                self::assert($this->originReader->verifiedForRun(
                    $other, $linearRun, $domain,
                    new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('UTC')),
                    new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC')),
                ) === [], 'Foreign tenant inspected another tenant origin journal.');
            }
            self::assert((int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM cos_federation_outcome_origins
                 WHERE organization_id = :org AND run_id = :run',
                ['org' => $org, 'run' => $linearRun],
            ) === 2, 'Origin journal did not persist immutable Research/Documents provenance fixtures.');
            try {
                $this->db->insert('cos_federation_outcome_origins', [
                    'organization_id' => $org, 'domain_id' => 'capital_markets',
                    'outcome_id' => $researchOriginFixture, 'run_id' => $linearRun,
                    'step_id' => 'a_second', 'action_id' => $secondAction['action_id'],
                    'source_fingerprint' => str_repeat('0', 64), 'linked_at' => $eventTime,
                ]);
                throw new \RuntimeException('The same Research outcome was attributed to two Federation Actions.');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            }

            $measured = $this->goals->recordEvaluation(
                $actor, 'eval-' . bin2hex(random_bytes(8)), $linearRun,
            );
            self::assert($measured['result'] === 'partial'
                && count($measured['criteria']) === 7
                && $measured['criteria'][0]['observed'] === 0
                && $measured['criteria'][0]['result'] === 'unsatisfied'
                && $measured['criteria'][1]['observed'] === 2
                && $measured['criteria'][1]['result'] === 'satisfied'
                && $measured['criteria'][1]['attribution'] === 'temporal_only'
                && $measured['criteria'][1]['source'] === 'growth.tn_growth_engagement_responses.v1'
                && $measured['criteria'][2]['observed'] === 1
                && $measured['criteria'][2]['result'] === 'satisfied'
                && $measured['criteria'][2]['attribution'] === 'run_linked_action'
                && $measured['criteria'][2]['verified_actions'] === 2
                && $measured['criteria'][2]['source'] === 'growth.inbound_responses.attested_action.v1'
                && $measured['criteria'][3]['observed'] === 1
                && $measured['criteria'][3]['result'] === 'satisfied'
                && $measured['criteria'][3]['attribution'] === 'temporal_only'
                && $measured['criteria'][3]['source'] === 'capital_markets.tn_capital_market_research_results.validated.v1'
                && $measured['criteria'][4]['observed'] === 1
                && $measured['criteria'][4]['result'] === 'satisfied'
                && $measured['criteria'][4]['source'] === 'platform.documents.cos_document_signatures.signed.v1'
                && $measured['criteria'][5]['observed'] === null
                && $measured['criteria'][5]['result'] === 'unverifiable'
                && $measured['criteria'][6]['observed'] === null
                && $measured['criteria'][6]['result'] === 'unverifiable',
                'End-to-end Growth / Research / Documents evidence did not match real tenant rows and status policies.');
            self::assert($this->goals->latestTrustedEvaluation($actor, $linearRun)['result'] === 'partial'
                && $this->goals->latestTrustedEvaluation($other, $linearRun) === null,
                'Cross-domain persisted Goal evaluation leaked tenant data or lost latest provenance.');

            // A forged or revoked canonical receipt cannot continue to
            // attribute an existing Growth reply to this Federation Run.
            $this->db->executeStatement(
                "UPDATE cos_actions SET status = 'FAILED'
                 WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => (string) $firstAction['action_id']],
            );
            $revoked = $this->goals->recordEvaluation(
                $actor, 'eval-' . bin2hex(random_bytes(8)), $linearRun,
            );
            self::assert($revoked['criteria'][1]['observed'] === 2
                && $revoked['criteria'][2]['observed'] === 0
                && $revoked['criteria'][2]['result'] === 'unsatisfied'
                && $revoked['criteria'][2]['attribution'] === 'run_linked_action'
                && $revoked['criteria'][2]['verified_actions'] === 1,
                'Revoked Action receipt incorrectly attributed a persisted Growth response.');
            $this->db->executeStatement(
                "UPDATE cos_actions SET status = 'COMPLETED'
                 WHERE organization_id = :org AND id = :id",
                ['org' => $org, 'id' => (string) $firstAction['action_id']],
            );
            $restored = $this->goals->recordEvaluation(
                $actor, 'eval-' . bin2hex(random_bytes(8)), $linearRun,
            );
            self::assert($restored['criteria'][2]['observed'] === 1
                && $restored['criteria'][2]['verified_actions'] === 2,
                'Restored verified receipt did not recover correct Run attribution.');
            self::assert($this->receiptReconciler->reconcile(
                $actor, $linearRun, 'z_first',
            )['status'] === 'completed',
                'Finalized Run lost read-only completed receipt attestation.');
            // A late worker cannot change Step state after Run finalization.
            $this->db->executeStatement(
                "UPDATE cos_federation_steps SET state = 'claimed'
                 WHERE organization_id = :org AND run_id = :run AND step_id = 'a_second'",
                ['org' => $org, 'run' => $linearRun],
            );
            try {
                $this->goals->finishStep($actor, $linearRun, 'a_second', 'completed',
                    'action:' . $secondAction['action_id']);
                throw new \RuntimeException('Late worker mutated a finalized Federation Run.');
            } catch (DomainException) {
            }
            $this->db->executeStatement(
                "UPDATE cos_federation_steps SET state = 'completed'
                 WHERE organization_id = :org AND run_id = :run AND step_id = 'a_second'",
                ['org' => $org, 'run' => $linearRun],
            );
            try {
                $this->sequentialOrchestrator->advance($other, $linearRun, $linearApprovalId);
                throw new \RuntimeException('Foreign tenant advanced Federation Run.');
            } catch (DomainException) {
            }

            // Real Research Action vertical slice. This synthetic tenant is
            // isolated by the outer database rollback; no market order exists.
            $researchResultId = 'research-' . bin2hex(random_bytes(12));
            $researchExperimentId = 'exp-' . bin2hex(random_bytes(12));
            $researchGoalId = 'goal-' . bin2hex(random_bytes(12));
            $researchPlanId = 'plan-' . bin2hex(random_bytes(12));
            $researchRunId = 'run-' . bin2hex(random_bytes(12));
            $this->db->insert('tn_capital_market_research_experiments', [
                'organization_id' => $org, 'experiment_id' => $researchExperimentId,
                'hypothesis_id' => 'hyp-smoke', 'dataset_id' => 'dataset-smoke',
                'strategy_version_id' => 'strategy-smoke',
                'status' => 'COMPLETED',
                'record_json' => json_encode([
                    'experiment_id' => $researchExperimentId, 'status' => 'COMPLETED',
                ], JSON_THROW_ON_ERROR),
                'created_at' => self::now(),
            ]);
            $researchParameters = [
                'experiment_id' => $researchExperimentId,
                'financial_metrics' => ['expected_pnl_average' => '1.25', 'sample_count' => 125],
                'risk_metrics' => ['max_drawdown' => '0.08'],
                'execution_metrics' => ['execution_fidelity' => 'PAPER'],
                'data_quality' => ['sample_count' => 125, 'skipped_count' => 0],
                'limitations' => ['Synthetic financial data: not an investment recommendation'],
                'review_evidence' => [
                    'decision' => 'VALIDATED',
                    'reviewed_by' => 'research-independent-approver',
                    'review_reference' => 'internal-fixture-review',
                ],
            ];
            $this->goals->createGoal($actor, new GoalSpecification(
                $researchGoalId, $org, 'user-smoke', 'Validate one reviewed Research result',
                [['id' => 'capital_markets.run_linked_validated_results', 'operator' => 'at_least', 'expected' => 1]],
                ['capital_markets.research.result.record'],
            ));
            self::assert(
                $this->capabilityBindings->requireExecutable($actor, RecordValidatedResearchResultHandler::TYPE)
                    ->ownerDomain === 'capital_markets',
                'Research canonical capability is not executable for enabled tenant.',
            );
            $this->goals->proposePlan($actor, $researchPlanId, $researchGoalId, [[
                'id' => 'research_result',
                'capability_id' => 'capital_markets.research.result.record',
                'capability_version' => '1.0.0',
                'input' => [
                    'target_type' => 'research_result',
                    'target_id' => $researchResultId,
                    'parameters' => $researchParameters,
                ],
            ]]);
            $researchPlanJson = (string) $this->db->fetchOne(
                'SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan',
                ['org' => $org, 'plan' => $researchPlanId],
            );
            $researchApprovalId = bin2hex(random_bytes(16));
            $researchApprovalParams = [
                'goal_id' => $researchGoalId, 'plan_id' => $researchPlanId,
                'specification_version' => 1, 'plan_hash' => hash('sha256', $researchPlanJson),
            ];
            $this->db->insert('cos_actions', [
                'id' => $researchApprovalId, 'organization_id' => $org,
                'type' => FederationPlanApproveHandler::ACTION_TYPE,
                'target_type' => 'cos_federation_plan', 'target_id' => $researchPlanId,
                'parameters' => json_encode($researchApprovalParams, JSON_THROW_ON_ERROR),
                'source_type' => 'USER', 'source_id' => 'user-smoke',
                'status' => 'RUNNING', 'execution_mode' => 'APPROVAL_REQUIRED',
                'risk_level' => 'LOW',
                'idempotency_key' => 'research-plan:' . $researchApprovalId,
                'correlation_id' => $researchApprovalId,
            ]);
            $this->db->insert('cos_policy_evaluations', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $researchApprovalId, 'decision' => 'APPROVAL_REQUIRED',
                'correlation_id' => $researchApprovalId, 'evaluated_at' => self::now(),
            ]);
            $this->db->insert('cos_approvals', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $researchApprovalId, 'status' => 'APPROVED',
                'approver_type' => 'USER', 'approver_id' => 'research-independent-approver',
                'requested_by_type' => 'USER', 'requested_by_id' => 'user-smoke',
                'decided_by_type' => 'USER', 'decided_by_id' => 'research-independent-approver',
                'decided_at' => self::now(),
            ]);
            $researchApprovalAction = new Action(
                $researchApprovalId, $org, FederationPlanApproveHandler::ACTION_TYPE,
                'cos_federation_plan', $researchPlanId, $researchApprovalParams,
                'USER', 'user-smoke', 'APPROVAL_REQUIRED', 'LOW',
                'research-plan:' . $researchApprovalId, new \DateTimeImmutable(),
                ActionStatus::Running, $researchApprovalId,
            );
            self::assert($this->approvalHandler->execute($researchApprovalAction)->successful,
                'Research plan activation through approved canonical Action failed.');
            $this->db->executeStatement(
                "UPDATE cos_actions SET status='COMPLETED'
                 WHERE organization_id=:org AND id=:id",
                ['org' => $org, 'id' => $researchApprovalId],
            );
            $this->goals->startApprovedRun($actor, $researchRunId, $researchPlanId, $researchApprovalId);
            $this->goals->transitionRun($actor, $researchRunId, 'pending', 'running', 1);
            $stepKey = $this->goals->claimStep($actor, $researchRunId, 'research_result');
            $researchActionId = bin2hex(random_bytes(16));
            $this->db->insert('cos_actions', [
                'id' => $researchActionId, 'organization_id' => $org,
                'type' => RecordValidatedResearchResultHandler::TYPE,
                'target_type' => 'research_result', 'target_id' => $researchResultId,
                'parameters' => json_encode($researchParameters, JSON_THROW_ON_ERROR),
                'source_type' => 'USER', 'source_id' => 'user-smoke',
                'status' => 'RUNNING', 'execution_mode' => 'APPROVAL_REQUIRED',
                'risk_level' => 'HIGH', 'idempotency_key' => 'fed:' . $stepKey,
                'correlation_id' => $researchActionId,
            ]);
            $this->db->insert('cos_policy_evaluations', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $researchActionId, 'decision' => 'APPROVAL_REQUIRED',
                'correlation_id' => $researchActionId, 'evaluated_at' => self::now(),
            ]);
            $this->db->insert('cos_approvals', [
                'id' => bin2hex(random_bytes(16)), 'organization_id' => $org,
                'action_id' => $researchActionId, 'status' => 'APPROVED',
                'approver_type' => 'USER', 'approver_id' => 'research-independent-approver',
                'requested_by_type' => 'USER', 'requested_by_id' => 'user-smoke',
                'decided_by_type' => 'USER', 'decided_by_id' => 'research-independent-approver',
                'decided_at' => self::now(),
            ]);
            $this->db->insert('cos_action_attempts', [
                'action_id' => $researchActionId, 'organization_id' => $org,
                'attempt' => 1, 'worker_id' => 'research-smoke-worker',
                'status' => 'RUNNING',
                'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->format('Y-m-d H:i:s.u'),
            ]);
            $researchAction = new Action(
                $researchActionId, $org, RecordValidatedResearchResultHandler::TYPE,
                'research_result', $researchResultId, $researchParameters,
                'USER', 'user-smoke', 'APPROVAL_REQUIRED', 'HIGH',
                'fed:' . $stepKey, new \DateTimeImmutable(),
                ActionStatus::Running, $researchActionId,
            );
            $this->actionAdmission->assertAuthorized($researchAction);
            $wrongAction = new Action(
                $researchActionId, $org, RecordValidatedResearchResultHandler::TYPE,
                'research_result', 'result-that-was-not-approved', $researchParameters,
                'USER', 'user-smoke', 'APPROVAL_REQUIRED', 'HIGH',
                'fed:' . $stepKey, new \DateTimeImmutable(),
                ActionStatus::Running, $researchActionId,
            );
            self::assert(!$this->researchResultHandler->execute($wrongAction)->successful,
                'Research handler accepted a forged approved target.');
            self::assert($this->researchRepository->getResultForExperiment($org, $researchExperimentId) === null,
                'Rejected Research Action wrote business state.');
            $researchExecution = $this->researchResultHandler->execute($researchAction);
            self::assert($researchExecution->successful
                && ($researchExecution->data['result_id'] ?? null) === $researchResultId
                && $this->researchRepository->getResultForExperiment($org, $researchExperimentId) !== null,
                'Approved Research Action did not atomically create native result and origin.');
            self::assert((int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM cos_federation_outcome_origins
                 WHERE organization_id=:org AND run_id=:run AND action_id=:action',
                ['org' => $org, 'run' => $researchRunId, 'action' => $researchActionId],
            ) === 1, 'Research Action did not persist exactly one native origin.');
            self::assert(!$this->researchResultHandler->execute($researchAction)->successful,
                'Research outcome replay bypassed uniqueness and immutable origin.');
            $this->db->executeStatement(
                "UPDATE cos_actions SET status='COMPLETED' WHERE organization_id=:org AND id=:id",
                ['org' => $org, 'id' => $researchActionId],
            );
            $this->db->executeStatement(
                "UPDATE cos_action_attempts SET status='COMPLETED', finished_at=NOW(6)
                 WHERE organization_id=:org AND action_id=:id AND attempt=1",
                ['org' => $org, 'id' => $researchActionId],
            );
            self::assert($this->receiptReconciler->reconcile(
                $actor, $researchRunId, 'research_result',
            )['status'] === 'completed', 'Research Action receipt not independently reconciled.');
            $stateBeforeFinal = $this->goals->run($actor, $researchRunId);
            $this->runFinalizer->finalize($actor, $researchRunId, (int) $stateBeforeFinal['revision']);
            $verifiedResearch = $this->originReader->verifiedForRun(
                $actor, $researchRunId, 'capital_markets',
                new \DateTimeImmutable('2026-10-01T00:00:00+00:00'),
                new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')),
            );
            self::assert(count($verifiedResearch) === 1
                && $verifiedResearch[0]['action_id'] === $researchActionId
                && $verifiedResearch[0]['outcome_id'] === $researchResultId,
                'Completed Research Run did not independently attest its Action-to-outcome provenance.');
            // Positive opt-in provider contract: default deployment flag
            // remains OFF, but this fixture proves true readiness can return
            // business evidence from native recorded sources.
            $readyResearch = new FederationNativeRunLinkedOutcomeEvidenceProvider(
                $this->originReader, $this->capabilityBindings,
                'capital_markets', 'capital_markets.run_linked_validated_results',
                RecordValidatedResearchResultHandler::TYPE, true,
            );
            $nativeEvidence = $readyResearch->observeRun(
                $actor, $researchRunId,
                'capital_markets.run_linked_validated_results',
                new \DateTimeImmutable('2026-10-01T00:00:00+00:00'),
                new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')),
            );
            self::assert($nativeEvidence['value'] === 1
                && $nativeEvidence['attribution'] === 'run_linked_action'
                && $nativeEvidence['verified_actions'] === 1
                && $nativeEvidence['run_id'] === $researchRunId,
                'Enabled Research provider failed to confirm real Run-linked outcome.');

            self::assert($this->originReader->verifiedForRun(
                $other, $researchRunId, 'capital_markets',
                new \DateTimeImmutable('2026-10-01T00:00:00+00:00'),
                new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')),
            ) === [], 'Foreign tenant accessed Research origin.');
            $oldResearchJson = (string) $this->db->fetchOne(
                'SELECT record_json FROM tn_capital_market_research_results
                 WHERE organization_id=:org AND result_id=:id',
                ['org' => $org, 'id' => $researchResultId],
            );
            $this->db->executeStatement(
                "UPDATE tn_capital_market_research_results SET record_json='{}'
                 WHERE organization_id=:org AND result_id=:id",
                ['org' => $org, 'id' => $researchResultId],
            );
            try {
                $this->originReader->verifiedForRun(
                    $actor, $researchRunId, 'capital_markets',
                    new \DateTimeImmutable('2026-10-01T00:00:00+00:00'),
                    new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')),
                );
                throw new \RuntimeException('Tampered native Research result was accepted as trusted.');
            } catch (DomainException) {
            }
            $this->db->executeStatement(
                'UPDATE tn_capital_market_research_results SET record_json=:record
                 WHERE organization_id=:org AND result_id=:id',
                ['record' => $oldResearchJson, 'org' => $org, 'id' => $researchResultId],
            );

            // Documents Action owns REQUESTING a human signature, not the
            // unverified signing event. Real Domain-owned Platform service,
            // canonical stored mutation and idempotent business receipt.
            $docId = 'DOC-' . bin2hex(random_bytes(12));
            $this->db->insert('cos_documents', [
                'organization_id' => $org, 'document_id' => $docId,
                'title' => 'Federation signature request fixture',
                'status' => 'active', 'created_by' => 1001, 'updated_by' => 1001,
            ]);
            $request = new Action(
                bin2hex(random_bytes(16)), $org, RequestSignatureHandler::TYPE,
                'document', $docId, ['signer_id' => 'external-human-signer'],
                'USER', '1001', 'APPROVAL_REQUIRED', 'HIGH',
                'fed:request-' . bin2hex(random_bytes(16)), new \DateTimeImmutable(),
                ActionStatus::Running,
            );
            $createdRequest = $this->signatureRequestHandler->execute($request);
            self::assert(
                $createdRequest->successful
                && ($createdRequest->data['status'] ?? null) === 'requested'
                && ($createdRequest->data['signature_verified'] ?? null) === false
                && is_string($createdRequest->data['signature_id'] ?? null),
                'Documents request Action falsely signed or failed to request.',
            );
            $signatureId = (string) $createdRequest->data['signature_id'];
            $recorded = $this->db->fetchAssociative(
                'SELECT organization_id, document_id, signer_id, status, signature_reference,
                        signed_at
                 FROM cos_document_signatures WHERE organization_id=:org AND signature_id=:sig',
                ['org' => $org, 'sig' => $signatureId],
            );
            self::assert(
                is_array($recorded) && $recorded['status'] === 'requested'
                && $recorded['document_id'] === $docId
                && $recorded['signer_id'] === 'external-human-signer'
                && $recorded['signed_at'] === null
                && $recorded['signature_reference'] === null,
                'A signature request was stored as a signed document.',
            );
            $replayedRequest = $this->signatureRequestHandler->execute($request);
            self::assert(
                $replayedRequest->successful
                && ($replayedRequest->data['signature_id'] ?? null) === $signatureId
                && (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM cos_document_signatures
                     WHERE organization_id=:org AND document_id=:doc',
                    ['org' => $org, 'doc' => $docId],
                ) === 1,
                'Documents signature request replay created another signature.',
            );
            $crossTenantRequest = new Action(
                bin2hex(random_bytes(16)), 'foreign-' . $org, RequestSignatureHandler::TYPE,
                'document', $docId, ['signer_id' => 'external-human-signer'],
                'USER', '1001', 'APPROVAL_REQUIRED', 'HIGH',
                'fed:foreign-' . bin2hex(random_bytes(16)), new \DateTimeImmutable(),
                ActionStatus::Running,
            );
            self::assert(!$this->signatureRequestHandler->execute($crossTenantRequest)->successful,
                'Documents accepted signature request against another tenant Document.');
            self::assert((int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM cos_federation_outcome_origins
                 WHERE organization_id=:org AND domain_id=:domain AND outcome_id=:id',
                ['org' => $org, 'domain' => 'documents', 'id' => $signatureId],
            ) === 0, 'Pending signature request was forged into verified outcome.');

            $this->verifyIncrementalCandidatePlans($org);
            $output->writeln('<info>COS Federation MySQL persistence smoke passed.</info>');
            return Command::SUCCESS;
        } catch (Throwable $failure) {
            $output->writeln('<error>COS Federation persistence smoke FAILED: '
                . $failure::class . ': ' . $failure->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            // Parent transaction includes nested Doctrine savepoints, leaving no fixture rows.
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
        }
    }

    /**
     * Transaction-scoped negative/positive acceptance of fifty immutable
     * proposed candidate Plans. Does not create Growth Candidates, run Domain
     * Actions, perform Sales handoff, generate/attach documents or send mail.
     */
    private function verifyIncrementalCandidatePlans(string $org): void
    {
        $actor = self::actor($org,'71');
        // The entire smoke runs in the existing outer DB transaction.
        // Tenant module activation is a synthetic fixture, never a release
        // toggle for an actual organization.
        foreach (['growth','sales','documents','federation'] as $domain) {
            $this->db->executeStatement(
                'INSERT INTO cos_organization_modules (organization_id,module_id,enabled)
                 VALUES (:org,:module,1)
                 ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)',
                ['org'=>$org,'module'=>$domain],
            );
        }
        foreach (['growth.candidate.qualify', 'growth.handoff.prepare',
                  'growth.handoff.target.sales','documents.proposal.prepare'] as $id) {
            $this->capabilityBindings->requireExecutable($actor,$id);
        }
        $goalId='goal-fanout-'.bin2hex(random_bytes(8));
        $specification=new GoalSpecification(
            $goalId,$org,'71','Propose fifty sourced and qualified candidates',
            [['id'=>'sales.won_deals','operator'=>'at_least','expected'=>1]],
            ['growth.candidate.qualify','growth.handoff.prepare',
             'growth.handoff.target.sales','documents.proposal.prepare'],
        );
        $this->goals->createGoal($actor,$specification);
        $native=[
            'organization_id'=>$org,'run_id'=>'GMRN-TEST','universe_id'=>'universe-50',
            'status'=>'completed','started_at'=>'2026-10-09 10:00:00.000000',
            'finished_at'=>'2026-10-09 10:20:00.000000',
        ];
        $members=[];
        for ($i=1;$i<=50;$i++) {
            $suffix=sprintf('%03d',$i);
            $members[]=[
                'organization_id'=>$org,'universe_id'=>'universe-50',
                'candidate_id'=>'candidate-'.$suffix,'account_id'=>'account-'.$suffix,
                'external_key_hash'=>hash('sha256','source-'.$suffix),
                'source_reference'=>'https://example.test/org/'.$suffix,
                'last_seen_at'=>'2026-10-09 10:10:00.000000',
                'account_name'=>'Company '.$suffix,
            ];
        }
        $view=static fn(string $id): ?array => [
            'organization_id'=>$org,'candidate_id'=>$id,
            'subject_type'=>'account',
            'subject_id'=>str_replace('candidate-','account-',$id),
            'target_domain'=>'sales','status'=>'scored',
            'score'=>['total'=>90],'rationale'=>['source'=>'fixture'],
            'lead_name'=>'Test Champion','lead_email'=>'champion@example.test',
        ];
        $options=[
            'policy_id'=>'policy-test','policy_revision'=>1,
            'template_id'=>'template-test','expected_value'=>'1000 USD',
            'recommended_play'=>'review','recommended_action'=>'schedule a call',
            'source_federation_run'=>'run-origin','source_federation_step'=>'discover',
        ];

        $first=$this->candidateFanout->build(
            $specification,$native,$members,$view,10,$options,
        );
        self::assert($first['ready']===true && count($first['plans'])===10,
            'First ten scored candidates should generate ten proposed Plans.');
        foreach ($first['plans'] as $plan) {
            $proposed=$this->goals->proposePlan($actor,$plan['plan_id'],$goalId,
                $plan['steps'],1,$plan['lineage']);
            self::assert($proposed['status']==='proposed','Fan-out Plans cannot auto-approve.');
        }

        $prior=array_column($first['plans'],'candidate_id');
        $second=$this->candidateFanout->build(
            $specification,$native,array_reverse($members),$view,40,$options,$prior,
        );
        self::assert($second['ready']===true && count($second['plans'])===40,
            'Incremental 40-candidate proposal must exclude original ten.');
        foreach ($second['plans'] as $plan) {
            $this->goals->proposePlan($actor,$plan['plan_id'],$goalId,
                $plan['steps'],1,$plan['lineage']);
        }

        $stored=$this->db->fetchAllAssociative(
            'SELECT plan_id,state,plan_json FROM cos_federation_plans
             WHERE organization_id = :org AND goal_id = :goal',
            ['org'=>$org,'goal'=>$goalId],
        );
        self::assert(count($stored)===50,'Exactly 50 tenant-owned proposed Plans must persist.');
        $candidates=[];
        foreach ($stored as $row) {
            $plan=json_decode((string)$row['plan_json'],true,512,JSON_THROW_ON_ERROR);
            $candidate=$plan['lineage']['candidate_id'] ?? null;
            self::assert($row['state']==='proposed'
                && is_string($candidate) && $candidate!==''
                && count($plan['steps']??[])===4,
                'Stored Plan must retain all four canonical steps and candidate provenance.');
            $candidates[$candidate]=true;
        }
        self::assert(count($candidates)===50,'Duplicate Candidate persisted under same Goal.');
        $foreign=$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_federation_plans
             WHERE organization_id=:foreign AND goal_id=:goal',
            ['foreign'=>'not-'.$org,'goal'=>$goalId],
        );
        self::assert((int)$foreign===0,'Fan-out Plan breached tenant scoping.');
        $runs=$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_federation_runs
             WHERE organization_id=:org AND goal_id=:goal',
            ['org'=>$org,'goal'=>$goalId],
        );
        self::assert((int)$runs===0,'Unapproved fan-out must not dispatch a Run.');
        try {
            $this->goals->proposePlan($actor,$first['plans'][0]['plan_id'],$goalId,
                $first['plans'][0]['steps'],1,$first['plans'][0]['lineage']);
            throw new \RuntimeException('Duplicate Goal Candidate Plan was allowed.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Native DB uniqueness is the final replay boundary.
        }
    }

    private static function actor(string $org, string $user): TenantContext
    {
        return new TenantContext(
            UserId::fromString($user),
            OrganizationId::fromString($org),
            OrganizationRole::fromString('manager'),
            [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)],
        );
    }

    private static function assert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s') . '.000000';
    }
}
