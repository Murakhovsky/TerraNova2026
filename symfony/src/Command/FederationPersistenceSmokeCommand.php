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
            $this->goals->startApprovedRun($actor, $runId, $planId);
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
