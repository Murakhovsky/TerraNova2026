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
use DateTimeImmutable;
use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Agent\Model\Agent;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentRunStatus;
use Kernel\Execution\ExecutionFailureClassifier;
use Kernel\Execution\ExecutionFailureKind;
use Kernel\Shared\Domain\OrganizationId;
use RuntimeException;

final readonly class EngineeringDomainAgentService
{
    public function __construct(
        private AgentRuntimeInterface $runtime,
        private EngineeringDomainStoreInterface $domains,
        private EngineeringAgentDefinitionFactory $definitions,
        private EngineeringDomainAgentOutputValidator $validator,
        private EngineeringDomainBudgetGuard $budgets,
        private EngineeringDomainHumanGateService $humanGates,
        private EngineeringSecretIsolationGuard $secrets = new EngineeringSecretIsolationGuard(),
        private AgentCapabilityRegistry $agentCapabilities = new AgentCapabilityRegistry(),
        private RuntimeCapabilityRegistry $runtimeCapabilities = new RuntimeCapabilityRegistry(),
        private int $maxTechnicalRetries = 2,
        private int $maxSemanticRetries = 2,
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
            AgentRole::DOCUMENTATION,
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

        $definition = $this->definitions->createDomainMode($role, $organizationId);
        $agent = new Agent('domain_'.strtolower($role->value), $definition, tags: ['engineering','domain-development']);
        $organization = OrganizationId::fromString($organizationId);
        $state = (string) ($this->domains->domain($domainId)['status'] ?? 'UNKNOWN');
        $auditInput = $this->secrets->sanitize([
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
        ]);
        $artifacts = $this->artifactRefs($inputs);
        $repositoryRevision = $this->repositoryRevision($inputs);

        $semanticRetry = 0;
        for ($technicalRetry = 0; ; ++$technicalRetry) {
            $runId = EngineeringId::generate();
            $instance = new AgentInstance($runId, $organization, $agent, [
                'engineering_domain_id' => $domainId,
                'engineering_mode' => 'DOMAIN_DEVELOPMENT',
                'technical_retry' => $technicalRetry,
                'semantic_retry' => $semanticRetry,
            ]);
            $runCorrelationId = mb_substr(
                rtrim($correlationId, ':').':domain-agent:'.$runId.':technical-'.($technicalRetry + 1).':semantic-'.($semanticRetry + 1),
                0,
                128,
            );
            $context = new AgentContext(
                organizationId: $organization,
                correlationId: $runCorrelationId,
                input: $auditInput,
                data: [
                    'engineering_domain_id' => $domainId,
                    'mode' => 'DOMAIN_DEVELOPMENT',
                    'technical_retry' => $technicalRetry,
                    'semantic_retry' => $semanticRetry,
                ],
                metadata: [
                    'engineering_domain_id' => $domainId,
                    'engineering_role' => $role->value,
                    'engineering_mode' => 'DOMAIN_DEVELOPMENT',
                    'technical_retry' => $technicalRetry,
                    'semantic_retry' => $semanticRetry,
                ],
            );

            $started = new DateTimeImmutable();
            $phase = 'RUNTIME';
            try {
                $run = $this->runtime->execute($instance, $context);
                $output = $run->output();
                if ($run->status() !== AgentRunStatus::COMPLETED) {
                    throw new RuntimeException($run->error() ?? 'Domain Development agent runtime failed.');
                }

                $phase = 'VALIDATION';
                if ($output === null || $output->structured === []) {
                    throw new RuntimeException('Domain Development agent did not return structured output.');
                }
                $this->validator->validate(
                    $role,
                    $output->structured,
                    isset($inputs['phase']) && is_string($inputs['phase']) ? $inputs['phase'] : null,
                );

                $finished = new DateTimeImmutable();
                $this->domains->recordAgentRun(
                    domainId: $domainId,
                    role: $role->value,
                    status: 'COMPLETED',
                    correlationId: $runCorrelationId,
                    provider: $output->provider,
                    model: $output->model,
                    usage: $output->usage,
                    error: null,
                    runtimeId: $runId,
                    featureId: null,
                    state: $state,
                    startedAt: $started->format('Y-m-d H:i:s.u'),
                    finishedAt: $finished->format('Y-m-d H:i:s.u'),
                    inputs: $auditInput,
                    outputs: $this->secrets->sanitize($output->structured),
                    artifacts: $artifacts,
                    repositoryRevision: $repositoryRevision,
                    errors: [],
                );

                return $output->structured;
            } catch (\Throwable $error) {
                $finished = new DateTimeImmutable();
                $output = isset($run) ? $run->output() : null;
                $kind = $phase === 'VALIDATION'
                    ? ExecutionFailureKind::Permanent
                    : ExecutionFailureClassifier::classify($error);
                $retryPolicy = $kind->retryable()
                    ? 'AUTO_RETRY'
                    : ($phase === 'VALIDATION' ? 'RETURN_TO_AGENT' : 'HUMAN_DECISION_REQUIRED');

                $this->domains->recordAgentRun(
                    domainId: $domainId,
                    role: $role->value,
                    status: 'FAILED',
                    correlationId: $runCorrelationId,
                    provider: $output?->provider,
                    model: $output?->model,
                    usage: $output?->usage ?? [],
                    error: $error->getMessage(),
                    runtimeId: $runId,
                    featureId: null,
                    state: $state,
                    startedAt: $started->format('Y-m-d H:i:s.u'),
                    finishedAt: $finished->format('Y-m-d H:i:s.u'),
                    inputs: $auditInput,
                    outputs: $this->secrets->sanitize($output?->structured ?? []),
                    artifacts: $artifacts,
                    repositoryRevision: $repositoryRevision,
                    errors: [[
                        'phase' => $phase,
                        'failure_kind' => $kind->value,
                        'retry_policy' => $retryPolicy,
                        'technical_retry' => $technicalRetry,
                        'semantic_retry' => $semanticRetry,
                        'message' => $error->getMessage(),
                    ]],
                );

                if ($phase === 'VALIDATION' && $semanticRetry < $this->maxSemanticRetries) {
                    ++$semanticRetry;
                    $auditInput['inputs']['semantic_retry_feedback'] = [
                        'attempt' => $semanticRetry,
                        'validation_error' => $error->getMessage(),
                        'instruction' => 'Return a corrected structured result that satisfies the same canonical schema and objective. Do not change scope or invent missing facts.',
                    ];
                    unset($run, $output);
                    --$technicalRetry;
                    continue;
                }

                if ($kind->retryable() && $technicalRetry < $this->maxTechnicalRetries) {
                    unset($run, $output);
                    continue;
                }

                if ($kind->retryable()) {
                    $domain = $this->domains->domain($domainId);
                    $resume = EngineeringDomainStatus::from((string) ($domain['status'] ?? EngineeringDomainStatus::BLOCKED->value));
                    $this->humanGates->request(
                        $domainId,
                        $organizationId,
                        'DOMAIN_AGENT_RETRY_'.strtoupper($role->value),
                        $resume,
                        'Retry '.$role->value.' after infrastructure retry budget exhaustion?',
                        'Engineering Runtime exhausted automatic infrastructure retries.',
                        [
                            'agent' => $role->value,
                            'failure_kind' => $kind->value,
                            'attempts' => $technicalRetry + 1,
                            'max_technical_retries' => $this->maxTechnicalRetries,
                            'last_error' => $error->getMessage(),
                            'repository_revision' => $repositoryRevision,
                        ],
                        'DOMAIN_AGENT_RUNTIME',
                        [
                            ['id' => 'CONTINUE', 'description' => 'Authorize another persisted runtime attempt.'],
                            ['id' => 'CANCEL', 'description' => 'Stop retrying this Domain agent.'],
                        ],
                    );
                }

                throw $error;
            }
        }
    }
    /** @param array<string,mixed> $inputs @return list<array<string,mixed>> */
    private function artifactRefs(array $inputs): array
    {
        $refs = [];
        $walk = function (mixed $value) use (&$walk, &$refs): void {
            if (!is_array($value)) return;
            $artifactId = $value['artifact_id'] ?? null;
            if (!is_string($artifactId) && isset($value['artifact_ref']['id']) && is_string($value['artifact_ref']['id'])) {
                $artifactId = $value['artifact_ref']['id'];
            }
            if (is_string($artifactId) && preg_match('/^[0-9a-fA-F-]{36}$/', $artifactId) === 1) {
                $version = $value['version'] ?? ($value['artifact_ref']['version'] ?? null);
                $type = $value['type'] ?? ($value['artifact_ref']['type'] ?? null);
                $hash = $value['content_hash'] ?? $value['hash'] ?? ($value['artifact_ref']['hash'] ?? null);
                $key = $artifactId.'|'.(string) $version;
                $refs[$key] = array_filter([
                    'artifact_id' => $artifactId,
                    'type' => is_scalar($type) ? (string) $type : null,
                    'version' => is_numeric($version) ? (int) $version : null,
                    'hash' => is_scalar($hash) ? (string) $hash : null,
                ], static fn (mixed $item): bool => $item !== null && $item !== '');
            }
            foreach ($value as $child) $walk($child);
        };
        $walk($inputs);
        return array_values($refs);
    }

    /** @param array<string,mixed> $inputs */
    private function repositoryRevision(array $inputs): ?string
    {
        $direct = $inputs['repository_revision'] ?? null;
        if (is_scalar($direct) && trim((string) $direct) !== '') return trim((string) $direct);

        $index = $inputs['repository_context_index'] ?? null;
        if (is_array($index)) {
            $revision = $index['revision'] ?? ($index['relevant_sections']['revision'] ?? null);
            if (is_scalar($revision) && trim((string) $revision) !== '') return trim((string) $revision);
        }

        foreach ($inputs as $value) {
            if (!is_array($value)) continue;
            $revision = $this->repositoryRevision($value);
            if ($revision !== null) return $revision;
        }
        return null;
    }

}
