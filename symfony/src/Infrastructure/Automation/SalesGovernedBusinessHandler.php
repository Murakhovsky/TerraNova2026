<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\SalesDirectInvocationPolicy;
use App\Persistence\Workflow\FailClosedWorkflowActionWorkerAdmission;
use DomainException;
use Domains\Sales\Automation\Action\SalesGovernedBusinessOperations;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesApprovalAuthority;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Tenant\Service\TenantContextFactory;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Throwable;

/** Canonical Action transport for the same native command adapters as human Sales. */
final readonly class SalesGovernedBusinessHandler implements IdempotentExternalActionHandlerInterface
{
    public function __construct(
        private iterable $adapters,
        private SalesDirectInvocationPolicy $policy,
        private MysqlSalesApprovalAuthority $approval,
        private FailClosedWorkflowActionWorkerAdmission $admission,
        private ActiveModuleResolver $modules,
        private CanonicalCapabilityCatalog $catalog,
        private IdentityResolverInterface $identities,
        private TenantContextFactory $tenants,
        private TransactionManagerInterface $transactions,
    ) {}

    public function supports(string $actionType): bool
    {
        return in_array($actionType, SalesGovernedBusinessOperations::types(), true);
    }

    public function idempotencyKey(Action $action): string
    {
        return ExternalActionIdempotency::resolve($action);
    }

    public function execute(Action $action): ExecutionResult
    {
        try {
            if (!$this->supports($action->type) || !$this->modules->isEnabled($action->organizationId, 'sales')
                || $action->status !== ActionStatus::Running || $action->sourceType !== 'USER'
                || !ctype_digit($action->sourceId) || (int) $action->sourceId < 1
                || (string) (int) $action->sourceId !== $action->sourceId
                || $action->executionMode !== 'APPROVAL_REQUIRED' || $action->riskLevel !== 'HIGH'
                || !is_string($action->idempotencyKey) || preg_match('/^wf:[a-f0-9]{64}$/D', $action->idempotencyKey) !== 1
                || $action->correlationId === '') {
                throw new DomainException('Approved canonical Sales business Action required.');
            }
            SalesGovernedBusinessOperations::assertTarget($action);
            $governed = $this->catalog->describe($action->type);
            $nativeId = SalesGovernedBusinessOperations::nativeId($action->type);
            $native = $this->catalog->describe($nativeId);
            if ($governed === null || $native === null || $governed->ownerDomain !== 'sales'
                || $governed->executionBinding !== 'action:'.$action->type || $governed->approvalPolicy !== 'required'
                || $governed->sideEffectLevel !== 'internal' || $governed->idempotency !== 'required'
                || $native->ownerDomain !== 'sales' || $native->kind !== 'command'
                || $native->executionBinding !== 'command:'.$nativeId || $native->sideEffectLevel !== 'internal'
                || $native->idempotency !== 'required' || $native->approvalPolicy === 'required'
                || $governed->outputSchema !== $native->outputSchema
                || $governed->version !== $native->version
                || in_array($native->lifecycle, ['retired','deprecated'], true)
                || in_array($governed->lifecycle, ['retired','deprecated'], true)) {
                throw new DomainException('Governed Sales capability drift.');
            }
            $validator = new CapabilityJsonInputValidator();
            $validator->validate($governed, ['target_type'=>$action->targetType, 'target_id'=>$action->targetId, 'parameters'=>$action->parameters]);
            $validator->validate($native, $action->parameters);
            // Retain the tenant rollout fence as well as the immutable Workflow admission.
            $this->admission->assertAuthorized($action);
            $adapter = null;
            foreach ($this->adapters as $candidate) {
                if ($candidate instanceof CapabilityInvocationAdapterInterface && $candidate->binding() === $native->executionBinding) {
                    if ($adapter !== null) throw new DomainException('Duplicate native Sales adapter.');
                    $adapter = $candidate;
                }
            }
            if ($adapter === null) throw new DomainException('Native Sales command adapter unavailable.');

            $authorize = function () use ($action, $native): \Kernel\Tenant\Model\TenantContext {
                $this->approval->assertApprovedBusinessExecution($action);
                $identity = $this->identities->resolve(
                    UserId::fromString($action->sourceId), OrganizationId::fromString($action->organizationId));
                if ($identity === null) throw new DomainException('Sales actor inactive.');
                $actor = $this->tenants->fromIdentity($identity);
                if (!$actor->isManager() || !$actor->allows(TenantPermissions::ACCESS)
                    || !$actor->allows(TenantPermissions::MANAGE)
                    || $actor->organizationId()->value() !== $action->organizationId
                    || $actor->userId()->value() !== $action->sourceId
                    || ($native->permission !== null && !$actor->allows($native->permission))) {
                    throw new DomainException('Live Sales actor permission missing.');
                }
                $this->policy->authorize($actor, $native, $action->parameters);
                return $actor;
            };
            if($nativeId==='sales.crm.inbox.process'){
                if(!$adapter instanceof \App\Infrastructure\Capability\SalesCrmInboxProcessAdapter)
                    throw new DomainException('CRM processing requires native claim-aware adapter.');
                $actor=$this->transactions->transactional($authorize);
                $result=$adapter->invoke($actor,$action->parameters,$this->idempotencyKey($action),$action->correlationId,$authorize);
                if(!is_array($result))throw new DomainException('Native CRM completion missing.');
                return ExecutionResult::success($result);
            }
            $result = $this->transactions->transactional(function () use ($action, $native, $adapter,$authorize): array {
                $actor=$authorize();
                $data = $this->policy->execute($actor, $native, $action->parameters,
                    fn () => ($adapter instanceof \App\Infrastructure\Capability\SalesPipelineStageManageAdapter
                        || $adapter instanceof \App\Infrastructure\Capability\SalesDealOutcomeRecordAdapter)
                        ? $adapter->invoke($actor,$action->parameters,$this->idempotencyKey($action),$action->correlationId,$action)
                        : $adapter->invoke($actor,$action->parameters,$this->idempotencyKey($action),$action->correlationId));
                if (!is_array($data)) throw new DomainException('Native Sales result missing.');
                return $data;
            });
            return ExecutionResult::success($result);
        } catch (Throwable) {
            return ExecutionResult::failure('Sales business operation rejected or uncertain; reconcile the canonical Run and native result before retry.');
        }
    }
}
