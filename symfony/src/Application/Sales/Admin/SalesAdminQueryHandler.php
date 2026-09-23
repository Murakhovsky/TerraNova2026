<?php
declare(strict_types=1);

namespace App\Application\Sales\Admin;

use DomainException;
use Domains\Sales\Application\Contract\SalesAdministrationReadModelInterface;
use Domains\Sales\Application\Contract\SalesAgentAdministrationInterface;
use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Domains\Sales\Application\Contract\SalesPipelineAdministrationInterface;
use Domains\Sales\Application\Contract\SalesPipelineGovernanceInterface;
use Domains\Sales\Application\Contract\SalesPolicyAdministrationInterface;
use Domains\Sales\Application\Contract\SalesRuleAdministrationInterface;
use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
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
        private SalesIntegrationAdministrationInterface $integrations,
        private SalesAdministrationReadModelInterface $health,
        private SalesWorkspaceOperationalReadModelInterface $workspace,
    ) {
    }

    /** @return array<string,mixed>|array<int,mixed> */
    public function __invoke(SalesAdminQuery $query): array
    {
        $org = $query->organizationId->value();
        $id = trim((string) $query->resourceId);
        $limit = max(1, min(200, (int) ($query->input['limit'] ?? 100)));

        return match ($query->operation) {
            'dashboard' => [
                'pipelines' => $this->workspace->pipelines($org),
                'metrics' => $this->workspace->metrics($org, 30),
            ],
            'page.pipelines' => [
                'pipelines' => $this->pipelines->pipelines($org),
            ],
            'page.rules' => [
                'rules' => $this->rules->rules($org),
                'catalog' => $this->rules->catalog(),
            ],
            'page.agents' => [
                'agents' => $this->agents->agents($org),
                'catalog' => $this->agents->catalog(),
            ],
            'page.actions' => [
                'actions' => $this->policies->actions($org),
                'catalog' => $this->policies->catalog($org),
            ],
            'page.teams' => [
                'teams' => $this->teams->teams($org),
                'users' => $this->teams->users($org),
                'catalog' => $this->teams->catalog(),
            ],
            'page.integrations' => [
                'integrations' => $this->integrationList($org),
                'catalog' => $this->integrations->catalog(),
                'routing' => $this->integrations->routingOptions($org),
            ],
            'page.health' => [
                'health' => $this->health->dashboard($org, $limit),
            ],

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

            'integration.catalog' => $this->integrations->catalog(),
            'integration.list' => $this->integrationList($org),
            'integration.routing_options' => $this->integrations->routingOptions($org),
            'integration.view' => $this->required($this->integrations->integration($org, $this->numericId($id)), 'Sales integration not found.'),
            'integration.revisions' => $this->integrations->revisions($org, $this->numericId($id), $limit),

            'health.dashboard' => $this->health->dashboard($org, $limit),
            default => throw new DomainException('Unsupported Sales admin query.'),
        };
    }

    /** @return list<array<string,mixed>> */
    private function integrationList(string $organizationId): array
    {
        $items = $this->integrations->integrations($organizationId);
        foreach ($items as &$item) {
            $full = $this->integrations->integration($organizationId, (int) ($item['id'] ?? 0));
            if ($full !== null) {
                $item = array_merge($item, $full);
            }
        }
        unset($item);

        return $items;
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

    private function numericId(string $value): int
    {
        $id = (int) $value;
        if ($id <= 0) {
            throw new DomainException('Invalid numeric Sales administration id.');
        }

        return $id;
    }

    private function revisionId(string $value): string
    {
        if ($value === '' || mb_strlen($value) > 191) {
            throw new DomainException('Invalid revision entity id.');
        }
        return $value;
    }
}
