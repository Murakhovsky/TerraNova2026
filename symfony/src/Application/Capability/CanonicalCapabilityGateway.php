<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityContract;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use LogicException;

/** Catalog projection + guarded dispatch to existing Domain Command/Query buses. */
final class CanonicalCapabilityGateway {
    /** @var array<string,CapabilityInvocationAdapterInterface> */
    private array $adapters=[];
    /** @param iterable<CapabilityInvocationAdapterInterface> $adapters */
    public function __construct(
        private readonly CanonicalCapabilityCatalog $catalog,
        private readonly ActiveModuleResolver $modules,
        private readonly FederationCapabilityBindingResolver $bindings,
        iterable $adapters,
    ) {
        foreach ($adapters as $adapter) {
            if (!$adapter instanceof CapabilityInvocationAdapterInterface) throw new LogicException('Invalid capability adapter.');
            $binding=$adapter->binding();
            if (!preg_match('/^(command|query):[a-z][a-z0-9_.]+$/D',$binding)||isset($this->adapters[$binding])) {
                throw new LogicException('Duplicate or invalid capability binding: '.$binding);
            }
            $this->adapters[$binding]=$adapter;
        }
    }

    /** @return list<array<string,mixed>> */
    public function inventory(TenantContext $actor): array {
        $this->assertManager($actor);
        $actions=$this->bindings->available($actor);
        $entries=[];
        foreach ($this->catalog->declared() as $id=>$owner) {
            if ($owner==='federation') continue; // Never make Plan Approval plannable.
            $entries[]=$this->entry($actor,$id,$owner,$this->catalog->describe($id),$actions);
        }
        return $entries;
    }

    /** @return array<string,mixed> */
    public function describe(TenantContext $actor,string $id):array {
        $this->assertManager($actor);
        $owner=$this->catalog->ownerOf($id);
        if ($owner===null||$owner==='federation') throw new DomainException('Unknown or internal capability.');
        return $this->entry($actor,$id,$owner,$this->catalog->describe($id),$this->bindings->available($actor));
    }

    /** @param array<string,mixed> $input */
    public function invoke(TenantContext $actor,string $id,array $input,string $origin,string $key,string $correlationId):mixed {
        $info=$this->describe($actor,$id);
        if (!in_array($info['status'],['direct_human','read_only'],true)) {
            throw new DomainException('Direct invocation blocked: '.$info['reason']);
        }
        $contract=$this->catalog->describe($id);
        if (!$contract instanceof CapabilityContract) throw new DomainException('Missing typed capability.');
        $adapter=$this->adapters[$contract->executionBinding]??null;
        if ($adapter===null) throw new DomainException('Missing live Domain adapter.');
        if ($contract->kind==='command') {
            if (!in_array($origin,['human_api','human_ui'],true)) {
                throw new DomainException('Automated mutations require separately approved canonical Workflow.');
            }
            if ($contract->approvalPolicy==='required'
                || in_array($contract->sideEffectLevel,['external','financial','irreversible'],true)) {
                throw new DomainException('Governed Action approval is required.');
            }
            if ($contract->idempotency!=='required'||trim($key)===''||strlen($key)>191) {
                throw new DomainException('Mutation requires bounded idempotency key.');
            }
        } elseif ($contract->kind!=='query'||$contract->sideEffectLevel!=='none') {
            throw new DomainException('Unapproved non-query read adapter.');
        }
        if (!in_array($origin,['human_api','human_ui','trusted_read'],true)
            || $correlationId===''||strlen($correlationId)>191) throw new DomainException('Untrusted source or trace.');
        // Same fail-closed local schema validator used by Federation approved Plans.
        // Validation occurs before any Domain side effect or idempotency claim.
        (new CapabilityJsonInputValidator())->validate($contract,$input);
        return $adapter->invoke($actor,$input,$key,$correlationId);
    }

    private function assertManager(TenantContext $actor):void {
        if (!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Tenant manager required.');
        }
    }

    /** @param list<string> $actions @return array<string,mixed> */
    private function entry(TenantContext $actor,string $id,string $owner,?CapabilityContract $contract,array $actions):array {
        $status='unavailable';$reason='Declarative-only capability without executable contract.';
        if ($contract!==null) {
            if (!$this->modules->isEnabled($actor->organizationId()->value(),$owner)) {
                $reason='Owner module inactive for tenant.';
            } elseif ($contract->permission!==null&&!$actor->allows($contract->permission)) {
                $reason='Missing permission.';
            } elseif (in_array($contract->lifecycle,['retired','deprecated'],true)) {
                $reason='Inactive capability revision.';
            } elseif (str_starts_with($contract->executionBinding,'action:')) {
                if (in_array($id,$actions,true)) {
                    $status='workflow_approval_required';$reason='Requires approved Platform Workflow and Action receipts.';
                } else $reason='No live Action handler or approval binding.';
            } elseif (isset($this->adapters[$contract->executionBinding])
                && $contract->kind==='query'&&$contract->sideEffectLevel==='none'
                && str_starts_with($contract->executionBinding,'query:')) {
                $status='read_only';$reason='Live Domain QueryBus adapter.';
            } elseif (isset($this->adapters[$contract->executionBinding])
                && $contract->kind==='command'&&$contract->sideEffectLevel==='internal'
                && $contract->approvalPolicy!=='required'&&$contract->idempotency==='required'
                && str_starts_with($contract->executionBinding,'command:')) {
                $status='direct_human';$reason='Authenticated human Domain CommandBus only.';
            } else $reason='No safe live binding.';
        }
        return ['id'=>$id,'owner_domain'=>$owner,'status'=>$status,'reason'=>$reason,'contract'=>$contract?->toArray()];
    }
}
