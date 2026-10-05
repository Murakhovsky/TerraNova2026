<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Agent\EngineeringAgentDefinitionFactory;
use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\Agent\AgentRole;
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
            input: [
                'question' => $objective,
                'inputs' => $inputs,
                'constraints' => [
                    'Operate only inside Engineering Domain Development Runtime.',
                    'Do not perform business operations of the target domain.',
                    'Do not use production secrets.',
                    'Do not mutate repository content from a domain-planning run.',
                    'Return only the required structured result.',
                ],
            ],
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
