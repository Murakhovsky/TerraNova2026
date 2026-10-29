<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityCatalogCoverageAudit;
use Kernel\Module\CapabilityContract;
use Kernel\Module\CapabilityVersionArchive;
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

    /**
     * A versioned read of the CURRENT trusted contract, not a historical
     * archive and never a permission to invoke a handler. A missing version
     * fails closed rather than silently returning whatever was deployed.
     * @return array<string,mixed>
     */
    public function versionedContract(TenantContext $actor, string $id, string $version): array
    {
        $info=$this->describe($actor,$id);
        $contract=$this->catalog->describe($id);
        if (!$contract instanceof CapabilityContract || $contract->version !== $version) {
            throw new DomainException('Requested Capability contract revision is unavailable.');
        }
        $snapshot=$this->catalog->snapshot($id);
        $this->catalog->resolvePinned($id,$version,$snapshot['contract_digest']);
        return [
            'id'=>$id,
            'version'=>$version,
            'definition_snapshot'=>$snapshot,
            'contract'=>$contract->toArray(),
            'tenant_execution_mode'=>$info['status'],
            'reason'=>$info['reason'],
            'historical_archive_available'=>false,
            'authorized_to_execute'=>false,
        ];
    }

    /** @return array<string,mixed> */
    public function readiness(TenantContext $actor, string $id): array
    {
        $info=$this->describe($actor,$id);
        return [
            'id'=>$id, 'owner_domain'=>$info['owner_domain'],
            'definition_readiness'=>$info['definition_readiness'],
            'contract_digest'=>$info['definition_snapshot']['contract_digest']??null,
            'binding_witness'=>$info['binding_witness'],
            'binding_scope'=>$info['binding_witness_scope'],
            'tenant_execution_mode'=>$info['status'],
            'reason'=>$info['reason'],
            'certified'=>false,
            'worker_deployment_verified'=>false,
            'native_business_outcome_verified'=>false,
            'grants_approval'=>false,
        ];
    }

    /**
     * Historical metadata, never an adapter binding or a new execution grant.
     * Caller must still pass owner/tenant authorization for catalog visibility.
     * @return array<string,mixed>
     */
    public function archivedContract(TenantContext $actor,string $id,string $version,string $digest): array
    {
        $current = $this->describe($actor,$id);
        $frozen=(new CapabilityVersionArchive(dirname(__DIR__,4)))->read($id,$version,$digest);
        if (($frozen['contract']['owner_domain'] ?? null) !== $current['owner_domain']) {
            throw new DomainException('Historical Capability owner differs from declared Domain.');
        }
        return [
            'id'=>$id,'version'=>$version,'contract_digest'=>$digest,
            'historical_snapshot'=>true,'frozen'=>$frozen,
            'execution_authorized'=>false,'compatible_handler_verified'=>false,
            'tenant_status'=>$current['status'],
            'grants_approval'=>false,
        ];
    }

    /**
     * Whole-domain definition quality audit. This is source-level governance,
     * not a live worker/tenant certification or execution authority.
     * @return array<string,mixed>
     */
    public function coverage(TenantContext $actor): array
    {
        $this->assertManager($actor);
        $report=(new CapabilityCatalogCoverageAudit($this->catalog))->report();
        // Never expose the internal plan-approval Capability through the
        // user-facing catalog or machine discovery endpoint.
        $report['capabilities']=array_values(array_filter($report['capabilities'],
            static fn(array $r):bool => $r['owner_domain'] !== 'federation'
        ));
        $report['internal_capabilities_excluded']=true;
        $report['tenant_authorization_evaluated']=false;
        return $report;
    }

    /**
     * Current request-container binding census for a specific tenant manager.
     * No CI attestation, worker cutover, certification or new execution rights.
     * @return array<string,mixed>
     */
    public function bindingAudit(TenantContext $actor): array
    {
        $rows = $this->inventory($actor);
        $counts = [
            'declared' => count($rows),
            'typed_schema_valid' => 0,
            'current_container_binding_witnessed' => 0,
            'workflow_approval_required' => 0,
            'unavailable' => 0,
        ];
        foreach ($rows as $row) {
            if (($row['definition_readiness'] ?? null) === 'SCHEMA_VALID') {
                ++$counts['typed_schema_valid'];
            }
            if (($row['binding_witness'] ?? 'UNVERIFIED') !== 'UNVERIFIED') {
                ++$counts['current_container_binding_witnessed'];
            }
            if (($row['status'] ?? null) === 'workflow_approval_required') {
                ++$counts['workflow_approval_required'];
            }
            if (($row['status'] ?? null) === 'unavailable') {
                ++$counts['unavailable'];
            }
        }
        return [
            'scope' => 'CURRENT_REQUEST_CONTAINER_AND_TENANT',
            'certified' => false,
            'worker_deployment_verified' => false,
            'native_business_evidence_verified' => false,
            'counts' => $counts,
            'capabilities' => $rows,
        ];
    }

    /**
     * Deterministic preflight for UI/AI plan proposals. It NEVER dispatches.
     * This is not a Policy/Approval grant or a worker-time authorization.
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function validateInput(TenantContext $actor, string $id, array $input): array
    {
        $info = $this->describe($actor, $id);
        if ($info['status'] === 'unavailable') {
            throw new DomainException('Capability not available: ' . $info['reason']);
        }
        $contract = $this->catalog->describe($id);
        if (!$contract instanceof CapabilityContract) {
            throw new DomainException('Missing typed capability contract.');
        }
        $snapshot = $this->catalog->snapshot($id);
        (new CapabilityJsonInputValidator())->validate($contract, $input);
        return [
            'valid' => true,
            'capability_id' => $id,
            'capability_version' => $contract->version,
            'contract_digest' => $snapshot['contract_digest'],
            'execution_mode' => $info['status'],
            'approval_policy' => $contract->approvalPolicy,
            'side_effect_level' => $contract->sideEffectLevel,
            'executed' => false,
            'authorization_granted' => false,
        ];
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
        // Both schemas must exist before ANY side effect occurs.
        // Definition snapshots never authorize execution by themselves.
        $this->catalog->snapshot($id);
        $validator = new CapabilityJsonInputValidator();
        $validator->validate($contract,$input);
        $result = $adapter->invoke($actor,$input,$key,$correlationId);
        // The side effect may already have happened. A bad result must never
        // become a "success" receipt or authorization to issue a new request.
        $validator->validateOutput($contract,$result);
        return $result;
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
        $snapshot = null;
        $definitionReadiness = $contract === null ? 'DECLARATIVE_ONLY' : 'SCHEMA_INVALID';
        if ($contract !== null) {
            try {
                $snapshot = $this->catalog->snapshot($id);
                $definitionReadiness = 'SCHEMA_VALID';
            } catch (DomainException) {
                // A missing or malformed schema is not an executable function.
                $status = 'unavailable';
                $reason = 'Capability contract schema missing or invalid.';
            }
        }
        // Handler-registration witness only. It does not certify an
        // individual worker, tenant permission, policy or business outcome.
        $bindingWitness = 'UNVERIFIED';
        if ($contract !== null && $status === 'workflow_approval_required') {
            $bindingWitness = 'ACTION_HANDLER_REGISTERED';
        } elseif ($contract !== null && in_array($status,['direct_human','read_only'],true)) {
            $bindingWitness = 'DOMAIN_ADAPTER_REGISTERED';
        }
        return [
            'id'=>$id,'owner_domain'=>$owner,'status'=>$status,'reason'=>$reason,
            'binding_witness'=>$bindingWitness,
            'binding_witness_scope'=>'CURRENT_REQUEST_CONTAINER_ONLY',
            'contract'=>$contract?->toArray(),'definition_readiness'=>$definitionReadiness,
            'definition_snapshot'=>$snapshot,
            // Runtime authorization is evaluated separately from a static digest.
            'invocation_authorized'=>in_array($status,['direct_human','read_only'],true),
        ];
    }
}
