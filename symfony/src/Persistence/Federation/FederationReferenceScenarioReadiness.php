<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DomainException;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/**
 * Read-only Package A preflight, NOT verification that the business Goal
 * succeeded. Only a tenant-executable canonical handler is acceptable.
 */
final readonly class FederationReferenceScenarioReadiness
{
    private const STAGES = [
        ['stage' => 'discover_candidates', 'domain' => 'growth',
            'capability' => 'growth.market.discovery',
            'proof' => '50 distinct sourced, tenant-owned prospects with dedupe evidence'],
        ['stage' => 'qualify_candidates', 'domain' => 'growth',
            'capability' => 'growth.candidate.qualify',
            'proof' => 'Recorded qualification decision and source for each candidate'],
        ['stage' => 'prepare_handoff', 'domain' => 'growth',
            'capability' => 'growth.handoff.prepare',
            'proof' => 'Canonical qualified → ready_for_handoff transition with human-approved expected value and contact play'],
        ['stage' => 'handoff_and_sales_intake', 'domain' => 'growth',
            'capability' => 'growth.handoff.target.sales',
            'requires_module' => 'sales',
            'proof' => 'Approved Growth handoff invokes the existing Sales-owned idempotent native Lead intake exactly once; accepted native Sales Lead ID is persisted and later verified by Documents'],
        ['stage' => 'prepare_proposals', 'domain' => 'documents',
            'capability' => 'documents.proposal.prepare',
            'proof' => 'Native proposal drafts, linked to qualified leads; not signature requests'],
    ];

    public function __construct(
        private CanonicalCapabilityCatalog $catalog,
        private FederationCapabilityBindingResolver $bindings,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(TenantContext $actor): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Reference readiness requires authenticated tenant manager.');
        }
        $ready = true;
        $stages = [];
        foreach (self::STAGES as $spec) {
            $id = $spec['capability'];
            $contract = $this->catalog->describe($id);
            $owner = $this->catalog->ownerOf($id);
            if ($owner !== null && $owner !== $spec['domain']) {
                $status = 'wrong_domain_owner';
            } elseif ($contract === null) {
                $status = $owner === null ? 'missing_capability' : 'manifest_only';
            } elseif ($contract->ownerDomain !== $spec['domain']) {
                $status = 'wrong_domain_owner';
            } elseif ($contract->kind !== 'command'
                || !str_starts_with($contract->executionBinding, 'action:')) {
                $status = 'non_action_contract';
            } else {
                try {
                    $this->bindings->requireExecutable($actor, $id);
                    // Growth owns handoff coordination, but the target Sales module
                    // owns the native CRM write. Both must be tenant-active.
                    $dependency = $spec['requires_module'] ?? null;
                    $status = is_string($dependency)
                        && !$this->bindings->isTenantModuleEnabled($actor, $dependency)
                        ? 'dependent_module_unavailable' : 'executable';
                } catch (DomainException) {
                    $status = 'handler_or_tenant_unavailable';
                }
            }
            if ($status !== 'executable') {
                $ready = false;
            }
            $stages[] = [
                'stage' => $spec['stage'],
                'owner_domain' => $spec['domain'],
                'capability_id' => $id,
                'status' => $status,
                'required_business_evidence' => $spec['proof'],
            ];
        }
        return [
            'reference' => 'growth_50_to_sales_to_proposals_v1',
            'execution_ready' => $ready,
            'business_outcome_verified' => false,
            'stages' => $stages,
        ];
    }
}
