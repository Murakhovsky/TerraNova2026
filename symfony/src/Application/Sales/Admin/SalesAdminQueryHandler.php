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
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class SalesAdminQueryHandler implements QueryHandlerInterface
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

    /** @return array<string,mixed>|array<int,mixed> */
    public function __invoke(SalesAdminQuery $query): array
    {
        $org = $query->organizationId->value();
        $id = trim((string) $query->resourceId);
        $limit = max(1, min(200, (int) ($query->input['limit'] ?? 100)));

        return match ($query->operation) {
            'pipeline.list' => $this->pipelines->pipelines($org),
            'pipeline.view' => $this->required($this->pipelines->pipeline($org, $this->id($id)), 'Pipeline not found.'),
            'pipeline.validate' => $this->pipelines->validatePipeline($org, $this->id($id)),
            'pipeline.lost_reasons' => $this->pipelines->lostReasons($org, $this->id($id)),
            'pipeline.revisions' => $this->pipelineGovernance->revisions($org, $this->id($id), $limit),

            'rule.catalog' => $this->rules->catalog(),
            'rule.list' => $this->rules->rules($org),
            'rule.view' => $this->required($this->rules->rule($org, $this->configId($id, 'rule')), 'Sales rule not found.'),
            'rule.dry_run' => $this->rules->dryRun($org, $this->configId($id, 'rule')),
            'rule.revisions' => $this->rules->revisions($org, $this->configId($id, 'rule'), $limit),

            'agent.catalog' => $this->agents->catalog(),
            'agent.list' => $this->agents->agents($org),
            'agent.view' => $this->required($this->agents->agent($org, $this->agentName($id)), 'Sales agent not found.'),
            'agent.revisions' => $this->agents->revisions($org, $this->agentName($id), $limit),

            'policy.catalog' => $this->policies->catalog($org),
            'policy.actions' => $this->policies->actions($org),
            'policy.revisions' => $this->policies->revisions($org, $this->configId($id, 'policy'), $limit),

            'team.catalog' => $this->teams->catalog(),
            'team.users' => $this->teams->users($org),
            'team.list' => $this->teams->teams($org),
            'team.view' => $this->required($this->teams->team($org, $this->configId($id, 'team')), 'Sales team was not found.'),
            'team.revisions' => $this->teams->revisions($org, $this->revisionId($id), $limit),
            default => throw new DomainException('Unsupported Sales admin query.'),
        };
    }

    /** @return array<string,mixed> */
    private function required(?array $value, string $message): array
    {
        return $value ?? throw new DomainException($message);
    }

    private function id(string $value): string
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

    private function revisionId(string $value): string
    {
        if ($value === '' || mb_strlen($value) > 191) {
            throw new DomainException('Invalid revision entity id.');
        }
        return $value;
    }
}
