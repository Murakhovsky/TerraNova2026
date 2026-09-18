<?php
declare(strict_types=1);

namespace App\Application\Sales\Admin;

use DomainException;
use Domains\Sales\Application\Contract\SalesAgentAdministrationInterface;
use Domains\Sales\Application\Contract\SalesPipelineAdministrationInterface;
use Domains\Sales\Application\Contract\SalesPipelineGovernanceInterface;
use Domains\Sales\Application\Contract\SalesPolicyAdministrationInterface;
use Domains\Sales\Application\Contract\SalesRuleAdministrationInterface;
use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class SalesAdminMutationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SalesPipelineAdministrationInterface $pipelines,
        private SalesPipelineGovernanceInterface $pipelineGovernance,
        private SalesRuleAdministrationInterface $rules,
        private SalesAgentAdministrationInterface $agents,
        private SalesPolicyAdministrationInterface $policies,
        private SalesTeamAdministrationInterface $teams,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(SalesAdminMutationCommand $command): array
    {
        if ($command->actorId <= 0) {
            throw new DomainException('Authenticated actor is invalid.');
        }

        $org = $command->organizationId->value();
        $actor = (string) $command->actorId;
        $id = trim((string) $command->resourceId);
        $input = $command->input;

        return match ($command->operation) {
            'pipeline.create' => $this->pipelines->createPipeline($org, $input, $actor),
            'pipeline.update' => $this->pipelines->updatePipeline($org, $this->pipelineId($id), $input, $actor),
            'pipeline.clone' => $this->pipelineGovernance->cloneToDraft($org, $this->pipelineId($id), $input, $actor),
            'pipeline.stage.create' => $this->pipelines->createStage($org, $this->pipelineId($id), $input, $actor),
            'pipeline.stage.update' => $this->pipelines->updateStage($org, $this->pipelineId($id), $input, $actor),
            'pipeline.reorder' => $this->reorder($org, $id, $input, $actor),
            'pipeline.transitions' => $this->transitions($org, $id, $input, $actor),
            'pipeline.lost_reason.create' => $this->pipelines->createLostReason($org, $this->pipelineId($id), $input, $actor),
            'pipeline.lost_reason.update' => $this->pipelines->updateLostReason($org, $this->pipelineId($id), $input, $actor),

            'rule.create' => $this->rules->createDraft($org, $input, $actor),
            'rule.update' => $this->rules->updateDraft($org, $this->configId($id, 'rule'), $input, $actor),
            'rule.activate' => $this->rules->activate($org, $this->configId($id, 'rule'), $this->version($input), $actor),
            'rule.disable' => $this->rules->disable($org, $this->configId($id, 'rule'), $this->version($input), $actor),
            'rule.archive' => $this->rules->archive($org, $this->configId($id, 'rule'), $this->version($input), $actor),
            'rule.restore-system' => $this->rules->restoreSystem($org, $this->configId($id, 'rule'), $this->version($input), $actor),

            'agent.update' => $this->agents->update($org, $this->agentName($id), $input, $this->version($input), $actor),
            'agent.test' => $this->agents->test($org, $this->agentName($id), $input, $actor),

            'policy.create' => $this->policies->create($org, $input, $actor),
            'policy.update' => $this->policies->update($org, $this->configId($id, 'policy'), $input, $this->version($input), $actor),
            'policy.archive' => $this->policies->archive($org, $this->configId($id, 'policy'), $this->version($input), $actor),
            'policy.preview' => $this->policies->preview($org, $input),

            'team.create' => $this->teams->createTeam($org, $input, $actor),
            'team.update' => $this->teams->updateTeam($org, $this->configId($id, 'team'), $input, $this->version($input), $actor),
            'team.member' => $this->teams->setMember(
                $org,
                $this->configId($id, 'team'),
                $this->positiveInt($input['user_id'] ?? null, 'Invalid user id.'),
                $input,
                $actor,
            ),
            'team.capabilities' => $this->teams->setCapabilities(
                $org,
                $this->positiveInt($id, 'Invalid user id.'),
                array_values(array_map('strval', (array) ($input['capabilities'] ?? []))),
                $actor,
            ),
            default => throw new DomainException('Unsupported Sales admin mutation.'),
        };
    }

    /** @param array<string,mixed> $input */
    private function reorder(string $org, string $id, array $input, string $actor): array
    {
        $this->pipelines->reorderStages(
            $org,
            $this->pipelineId($id),
            (array) ($input['stages'] ?? []),
            $this->version($input, 'version'),
            $actor,
        );
        return ['saved' => true];
    }

    /** @param array<string,mixed> $input */
    private function transitions(string $org, string $id, array $input, string $actor): array
    {
        $this->pipelines->replaceTransitions(
            $org,
            $this->pipelineId($id),
            (array) ($input['transitions'] ?? []),
            $this->version($input, 'version'),
            $actor,
        );
        return ['saved' => true];
    }

    /** @param array<string,mixed> $input */
    private function version(array $input, string $key = 'configuration_version'): int
    {
        $version = (int) ($input[$key] ?? $input['version'] ?? 0);
        if ($version <= 0) {
            throw new DomainException('configuration_version is required.');
        }
        return $version;
    }

    private function pipelineId(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value)) {
            throw new DomainException('Invalid pipeline configuration id.');
        }
        return $value;
    }

    private function configId(string $value, string $kind): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{8,64}$/', $value)) {
            throw new DomainException('Invalid ' . $kind . ' id.');
        }
        return $value;
    }

    private function agentName(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{3,160}$/', $value)) {
            throw new DomainException('Invalid agent name.');
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $message): int
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new DomainException($message);
        }
        return (int) $value;
    }
}
