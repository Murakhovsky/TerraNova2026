<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use Kernel\Module\CanonicalCapabilityCatalog;

/**
 * Deterministic capability resolution for proposed steps. No dispatch or SQL.
 */
final readonly class GoalPlanValidator
{
    public function __construct(private CanonicalCapabilityCatalog $catalog)
    {
    }

    /**
     * @param list<array{id:string,capability_id:string,capability_version:string}> $steps
     * @param list<string> $tenantAvailableCapabilities From authoritative ActiveModule/permission/policy resolution
     * @return array{valid:bool,errors:list<string>,steps:list<array<string,mixed>>}
     */
    public function validate(
        GoalSpecification $specification,
        array $steps,
        array $tenantAvailableCapabilities,
    ): array {
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
            $normalized[] = [
                'id' => $id,
                'capability_id' => $capabilityId,
                'capability_version' => $capability->version,
                'owner_domain' => $capability->ownerDomain,
                'execution_binding' => $capability->executionBinding,
                'side_effect_level' => $capability->sideEffectLevel,
                'approval_policy' => $capability->approvalPolicy,
                'idempotency' => $capability->idempotency,
            ];
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
