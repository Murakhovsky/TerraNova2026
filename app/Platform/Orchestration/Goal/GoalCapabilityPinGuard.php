<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityContractFingerprint;

/**
 * Pure read-only guard for the immutable Capability fingerprints in new
 * approved Goal Plans. No handler invocation, claims, Action/Run writes.
 *
 * Legacy approved Plans without fingerprints are allowed through existing
 * pre-existing approval / version / binding / receipt gates. A partially
 * upgraded Plan is always rejected; migration is never silently inferred.
 */
final readonly class GoalCapabilityPinGuard
{
    public function __construct(private CanonicalCapabilityCatalog $catalog)
    {
    }

    /** @param list<array<string,mixed>> $steps */
    public function assertPlan(array $steps): void
    {
        if ($steps === [] || !array_is_list($steps)) {
            throw new DomainException('Invalid pinned Goal Plan step graph.');
        }
        $pinned = 0;
        foreach ($steps as $step) {
            if (!is_array($step)) {
                throw new DomainException('Invalid pinned Goal Plan step.');
            }
            if (array_key_exists('contract_digest', $step)
                || array_key_exists('input_digest', $step)) {
                ++$pinned;
            }
        }
        if ($pinned === 0) {
            // Pre-cutover, already-approved Plans retain their original
            // controls. Do not rewrite them or invent retrospective digests.
            return;
        }
        if ($pinned !== count($steps)) {
            throw new DomainException('Mixed pinned/legacy Goal Plan cannot execute.');
        }
        foreach ($steps as $step) {
            $id = $step['capability_id'] ?? null;
            $version = $step['capability_version'] ?? null;
            $digest = $step['contract_digest'] ?? null;
            $inputDigest = $step['input_digest'] ?? null;
            if (!is_string($id) || $id === ''
                || !is_string($version) || $version === ''
                || !is_string($digest) || !is_string($inputDigest)
                || !preg_match('/^[a-f0-9]{64}$/D', $digest)
                || !preg_match('/^[a-f0-9]{64}$/D', $inputDigest)) {
                throw new DomainException('Goal Step lacks exact immutable Capability fingerprint.');
            }
            $contract = $this->catalog->resolvePinned($id, $version, $digest);
            foreach ([
                'owner_domain' => $contract->ownerDomain,
                'execution_binding' => $contract->executionBinding,
                'side_effect_level' => $contract->sideEffectLevel,
                'approval_policy' => $contract->approvalPolicy,
                'idempotency' => $contract->idempotency,
            ] as $key => $expected) {
                if (($step[$key] ?? null) !== $expected) {
                    throw new DomainException('Pinned Goal Step authority differs from Capability contract.');
                }
            }
            if (!hash_equals(CapabilityContractFingerprint::inputDigest($step['input'] ?? null), $inputDigest)) {
                throw new DomainException('Pinned Goal Step input differs from approved parameters.');
            }
        }
    }
}
