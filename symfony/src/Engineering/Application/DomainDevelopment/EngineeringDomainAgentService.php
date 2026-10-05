<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Agent\EngineeringAgentDefinitionFactory;
use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Policy\AgentCapabilityRegistry;
use App\Engineering\Application\Policy\RuntimeCapabilityRegistry;
use App\Engineering\Application\Security\EngineeringSecretIsolationGuard;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\Workflow\EngineeringId;
use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Agent\Model\Agent;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentRunStatus;
use Kernel\Shared\Domain\OrganizationId;
use RuntimeException;

final readonly class EngineeringDomainAgentService
{
    public function __construct(
        private AgentRuntimeInterface $runtime,
        private EngineeringDomainStoreInterface $domains,
        private EngineeringAgentDefinitionFactory $definitions,
        private EngineeringDomainAgentOutputValidator $validator,
        private EngineeringSecretIsolationGuard $secrets = new EngineeringSecretIsolationGuard(),
        private AgentCapabilityRegistry $agentCapabilities = new AgentCapabilityRegistry(),
        private RuntimeCapabilityRegistry $runtimeCapabilities = new RuntimeCapabilityRegistry(),
        private EngineeringDomainBudgetGuard $budgets,
        private EngineeringDomainHumanGateService $humanGates,
    ) {}

    /** @param array<string,mixed> $inputs @return array<string,mixed> */
    public function run(
        string $domainId,
        string $organizationId,
        AgentRole $role,
        string $objective,
        array $inputs,
        string $correlationId,
    ): array {
        if (!in_array($role, [
            AgentRole::ENGINEERING_MANAGER,
            AgentRole::PRODUCT_REQUIREMENTS,
            AgentRole::QA_PLANNER,
            AgentRole::PRINCIPAL_ARCHITECT,
            AgentRole::QA_EXECUTOR,
            AgentRole::INTEGRATION_RELEASE,
            AgentRole::QA,
        ], true)) {
            throw new RuntimeException('Unsupported Domain Development agent role: '.$role->value);
        }

        $budget = $this->budgets->agentRunDecision($domainId, [
            'role' => $role->value,
            'objective' => $objective,
            'inputs' => $inputs,
        ]);
        if (($budget['allowed'] ?? false) !== true) {
            $domain = $this->domains->domain($domainId);
            $resume = EngineeringDomainStatus::from((string) ($domain['status'] ?? EngineeringDomainStatus::BLOCKED->value));
            $this->humanGates->request(
                $domainId,
                $organizationId,
                'DOMAIN_RESOURCE_BUDGET',
                $resume,
                'Extend Domain Engineering resource budget?',
                implode(' ', $budget['reasons'] ?? ['Domain resource budget exhausted.']),
                [
                    'role' => $role->value,
                    'budget' => $budget['evidence'] ?? [],
                ],
                'DOMAIN_BUDGET_GUARD',
                [
                    ['id' => 'CONTINUE', 'description' => 'Authorize one additional Domain resource-budget window and resume.'],
                    ['id' => 'CANCEL', 'description' => 'Do not extend the Domain resource budget.'],
                ],
            );
            throw new RuntimeException('Domain resource budget requires a human decision before '.$role->value.'.');
        }

        $runId = EngineeringId::generate();
        $definition = $this->definitions->createDomainMode($role, $organizationId);
        $agent = new Agent('domain_'.strtolower($role->value), $definition, tags: ['engineering','domain-development']);
        $organization = OrganizationId::fromString($organizationId);
        $instance = new AgentInstance($runId, $organization, $agent, [
            'engineering_domain_id' => $domainId,
            'engineering_mode' => 'DOMAIN_DEVELOPMENT',
        ]);
        $runCorrelationId = mb_substr(rtrim($correlationId, ':').':domain-agent:'.$runId, 0, 128);
        $context = new AgentContext(
            organizationId: $organization,
            correlationId: $runCorrelationId,
            input: $this->secrets->sanitize([
                'question' => $objective,
                'inputs' => $inputs,
                'agent_capabilities' => $this->agentCapabilities->forRole($role),
                'runtime_capabilities' => $this->runtimeCapabilities->forRuntime('EngineeringRuntime'),
                'constraints' => [
                    'Operate only inside Engineering Domain Development Runtime.',
                    'Do not perform business operations of the target domain.',
                    'Do not use production secrets.',
                    'Only secret references such as env://, vault:// or secret:// may cross the Engineering Agent boundary.',
                    'Do not mutate repository content from a domain-planning run.',
                    'Return only the required structured result.',
                ],
            ]),
            data: [
                'engineering_domain_id' => $domainId,
                'mode' => 'DOMAIN_DEVELOPMENT',
            ],
            metadata: [
                'engineering_domain_id' => $domainId,
                'engineering_role' => $role->value,
                'engineering_mode' => 'DOMAIN_DEVELOPMENT',
            ],
        );

        $failureRecorded = false;
        try {
            $run = $this->runtime->execute($instance, $context);
            $output = $run->output();
            if ($run->status() !== AgentRunStatus::COMPLETED || $output === null || $output->structured === []) {
                $error = $run->error() ?? 'Domain Development agent did not return structured output.';
                $this->domains->recordAgentRun($domainId, $role->value, 'FAILED', $runCorrelationId, $output?->provider, $output?->model, $output?->usage ?? [], $error);
                $failureRecorded = true;
                throw new RuntimeException($error);
            }

            $this->validator->validate($role, $output->structured, isset($inputs['phase']) && is_string($inputs['phase']) ? $inputs['phase'] : null);

            $this->domains->recordAgentRun(
                $domainId,
                $role->value,
                'COMPLETED',
                $runCorrelationId,
                $output->provider,
                $output->model,
                $output->usage,
                null,
            );

            return $output->structured;
        } catch (\Throwable $error) {
            if (!$failureRecorded) {
                $output = isset($run) ? $run->output() : null;
                $this->domains->recordAgentRun(
                    $domainId,
                    $role->value,
                    'FAILED',
                    $runCorrelationId,
                    $output?->provider,
                    $output?->model,
                    $output?->usage ?? [],
                    $error->getMessage(),
                );
            }
            throw $error;
        }
    }
}
