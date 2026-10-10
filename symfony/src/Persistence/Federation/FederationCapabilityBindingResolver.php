<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityContract;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Tenant\Model\TenantContext;

/**
 * Cross-domain executable binding authority. A manifest string is not a handler.
 * Never exposes internal plan-approval actions as user-plannable capabilities.
 */
final readonly class FederationCapabilityBindingResolver
{
    public function __construct(
        private CanonicalCapabilityCatalog $catalog,
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
    ) {}

    /** @return list<string> */
    public function available(TenantContext $actor): array
    {
        $result = [];
        foreach ($this->catalog->executables() as $contract) {
            if ($this->isAvailable($actor, $contract)) {
                $result[] = $contract->id;
            }
        }
        sort($result);
        return $result;
    }

    public function requireExecutable(TenantContext $actor, string $id): CapabilityContract
    {
        $contract = $this->catalog->describe($id);
        if ($contract === null || !$this->isAvailable($actor, $contract)) {
            throw new DomainException('Capability is not available for this tenant and role: ' . $id);
        }
        return $contract;
    }

    /** Module activation is required for a cross-domain side effect even if its
     * canonical Action is owned by the calling Domain. Never infer this from
     * a manifest capability string.
     */
    public function isTenantModuleEnabled(TenantContext $actor, string $moduleId): bool
    {
        return $actor->isManager()
            && $this->modules->isEnabled($actor->organizationId()->value(), $moduleId);
    }

    private function isAvailable(TenantContext $actor, CapabilityContract $contract): bool
    {
        // The federation.plan.approval Action authorizes plans; it is not a
        // business capability a Goal can use to self-authorize itself.
        if ($contract->ownerDomain === 'federation'
            || $contract->lifecycle === 'deprecated'
            || $contract->lifecycle === 'retired'
            || !$actor->isManager()
            || !is_string($contract->permission)
            || !$actor->allows($contract->permission)
            || $contract->kind !== 'command'
            || !str_starts_with($contract->executionBinding, 'action:')
            || !$this->modules->isEnabled($actor->organizationId()->value(), $contract->ownerDomain)) {
            return false;
        }
        $actionType = substr($contract->executionBinding, 7);
        $handlers = $this->domains->actionHandlerMap();
        if ($actionType === '' || $this->domains->ownerOfAction($actionType) !== $contract->ownerDomain
            || !isset($handlers[$actionType]) || !$handlers[$actionType]->supports($actionType)) {
            return false;
        }
        if ($contract->sideEffectLevel !== 'none' &&
            ($contract->idempotency !== 'required' || $contract->approvalPolicy !== 'required')) {
            return false;
        }
        return true;
    }
}
