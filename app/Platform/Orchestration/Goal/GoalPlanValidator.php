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
            $input = $step['input'] ?? null;
            if (in_array($capability->sideEffectLevel, ['external', 'financial', 'irreversible'], true)
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
            $normalized[] = [
                'id' => $id,
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
