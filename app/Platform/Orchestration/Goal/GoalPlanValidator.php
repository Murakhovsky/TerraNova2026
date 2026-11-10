<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityContractFingerprint;

/**
 * Deterministic capability resolution for proposed steps. No dispatch or SQL.
 */
final readonly class GoalPlanValidator
{
    public function __construct(private CanonicalCapabilityCatalog $catalog)
    {
    }

    /**
     * @param list<array{id:string,capability_id:string,capability_version:string,depends_on?:list<string>}> $steps
     * @param list<string> $tenantAvailableCapabilities From authoritative ActiveModule/permission/policy resolution
     * @return array{valid:bool,errors:list<string>,steps:list<array<string,mixed>>}
     */
    public function validate(
        GoalSpecification $specification,
        array $steps,
        array $tenantAvailableCapabilities,
        bool $pinContractSnapshot = false,
    ): array {
        // Preserve implicit sequential execution for legacy plans. New Plans
        // may declare explicit DAG edges; reject cycles and unknown nodes
        // before any proposed Plan can be approved.
        try {
            $dependencies = (new FederationStepDependencyGraph())->resolve($steps);
        } catch (\DomainException $exception) {
            return ['valid' => false,
                'errors' => ['invalid_plan_dependencies:' . $exception->getMessage()],
                'steps' => []];
        }
        $errors = [];
        $normalized = [];
        $seen = [];
        foreach ($steps as $step) {
            $id = $step['id'] ?? null;
            $capabilityId = $step['capability_id'] ?? null;
            $version = $step['capability_version'] ?? null;
            if (!is_string($id) || $id === '' || isset($seen[$id])
                || !is_string($capabilityId) || $capabilityId === ''
                || !is_string($version) || $version === '') {
                $errors[] = 'invalid_or_duplicate_step';
                continue;
            }
            $seen[$id] = true;
            $capability = $this->catalog->describe($capabilityId);
            if ($capability === null || $capability->version !== $version
                || !in_array($capabilityId, $specification->allowedCapabilities, true)
                || !in_array($capabilityId, $tenantAvailableCapabilities, true)
                || $capability->lifecycle === 'retired') {
                $errors[] = 'unavailable_or_unapproved_capability:' . $capabilityId;
                continue;
            }
            $input = $step['input'] ?? null;
            if ((in_array($capability->sideEffectLevel, ['external', 'financial', 'irreversible'], true)
                || ($capability->sideEffectLevel === 'internal'
                    && str_starts_with($capability->executionBinding, 'action:')))
                && $input === null) {
                $errors[] = 'missing_approved_action_input:' . $capabilityId;
                continue;
            }
            if ($input !== null) {
                try {
                    (new CapabilityJsonInputValidator())->validate($capability, $input);
                } catch (\DomainException $exception) {
                    $errors[] = 'invalid_action_input:' . $capabilityId . ':' . $exception->getMessage();
                    continue;
                }
            }
            $pinnedEvidence = null;
            if ($capability->sideEffectLevel === 'none'
                && str_starts_with($capability->executionBinding,'read:')) {
                $pinnedEvidence = $step['evidence'] ?? null;
                if (!is_array($pinnedEvidence) || array_is_list($pinnedEvidence)
                    || ($pinnedEvidence['kind'] ?? null)
                        !== substr($capability->executionBinding,5)
                    || !is_string($pinnedEvidence['reference'] ?? null)
                    || $pinnedEvidence['reference'] === ''
                    || strlen(json_encode($pinnedEvidence, JSON_THROW_ON_ERROR)) > 4096
                    || $input !== null) {
                    $errors[] = 'invalid_domain_read_evidence:' . $capabilityId;
                    continue;
                }
            }
            $pin = [];
            if ($pinContractSnapshot) {
                try {
                    $snapshot = $this->catalog->snapshot($capabilityId);
                    $pin = [
                        'contract_digest' => $snapshot['contract_digest'],
                        'input_digest' => CapabilityContractFingerprint::inputDigest($input),
                    ];
                } catch (\DomainException $exception) {
                    $errors[] = 'unverifiable_capability_contract:' . $capabilityId;
                    continue;
                }
            }
            $normalized[] = [
                'id' => $id,
                'depends_on' => $dependencies[$id],
                'capability_id' => $capabilityId,
                'capability_version' => $capability->version,
                'owner_domain' => $capability->ownerDomain,
                'execution_binding' => $capability->executionBinding,
                'side_effect_level' => $capability->sideEffectLevel,
                'approval_policy' => $capability->approvalPolicy,
                'idempotency' => $capability->idempotency,
                // Immutable, schema-validated step inputs are included in the
                // SHA-256 approved plan snapshot; execution must never use
                // caller-supplied parameters after approval.
                'input' => $input,
            ] + $pin + ($pinnedEvidence === null ? [] : ['evidence' => $pinnedEvidence]);
        }
        if ($normalized === []) {
            $errors[] = 'empty_executable_plan';
        }
        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'steps' => $errors === [] ? $normalized : [],
        ];
    }
}
