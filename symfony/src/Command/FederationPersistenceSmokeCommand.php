<?php
declare(strict_types=1);

namespace App\Command;

use App\Persistence\Federation\FederationExperiencePreferenceStore;
use App\Persistence\Federation\FederationGoalStore;
use App\Web\Experience\Adaptive\ExperienceMode;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use LogicException;
use Platform\Orchestration\Goal\GoalSpecification;
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
        private readonly FederationExperiencePreferenceStore $preferences,
        private readonly Environment $twig,
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
                ExperienceMode::Result->value => ['data-experience-mode="result"', 'Ваші бізнес-цілі', 'csrf_token'],
                ExperienceMode::Process->value => ['data-experience-mode="process"', 'data-goal-process'],
                ExperienceMode::Expert->value => ['data-experience-mode="expert"', 'data-goal-expert'],
            ] as $mode => $markers) {
                $html = $this->twig->render('experience/federation/goals.html.twig', [
                    'mode' => $mode,
                    'goals' => $this->goals->listGoals($actor),
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
                'status' => 'QUEUED', 'execution_mode' => 'APPROVAL_REQUIRED', 'risk_level' => 'LOW',
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
                'status' => 'QUEUED', 'execution_mode' => 'APPROVAL_REQUIRED', 'risk_level' => 'LOW',
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

            $evaluation = $this->goals->recordEvaluation(
                $actor, 'eval-' . bin2hex(random_bytes(8)), $goalId,
                ['accepted_leads' => ['value' => 5, 'evidence' => ['sales:accepted-leads:5']]],
            );
            self::assert($evaluation['result'] === 'satisfied',
                'Durable Goal result did not preserve verified evidence.');
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
