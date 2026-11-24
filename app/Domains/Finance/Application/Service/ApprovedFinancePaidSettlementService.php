<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;

use DomainException;
use Domains\Finance\Application\Contract\FinancePaidSettlementEvidencePort;
use Domains\Finance\Application\Contract\FinancePaidSettlementStore;
use Domains\Finance\Domain\BudgetControlPolicy;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Canonical financial worker service, with source reads inside the Finance transaction. */
final readonly class ApprovedFinancePaidSettlementService
{
    public const TYPE='finance.ad_spend.settlement.commit';
    public function __construct(private FinancePaidSettlementStore $store,private FinancePaidSettlementEvidencePort $evidence,
        private WorkflowActionWorkerAdmissionInterface $admission,private ActiveModuleResolver $modules){}
    public function execute(Action $action,TenantContext $actor):array
    {
        $org=$actor->organizationId()->value();$p=$action->parameters;
        if($action->type!==self::TYPE||$action->status!==ActionStatus::Running||$action->sourceType!=='USER'
            ||$action->sourceId!==$actor->userId()->value()||$org!==$action->organizationId
            ||$action->targetType!=='finance_reservation'||$action->executionMode!=='APPROVAL_REQUIRED'
            ||$action->riskLevel!=='HIGH'||!preg_match('/^wf:[a-f0-9]{64}$/D',(string)$action->idempotencyKey)
            ||!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'finance')||!$this->modules->isEnabled($org,'marketing')){
            throw new DomainException('Approved financial Workflow and active tenant authority required.');
        }
        $keys=array_keys($p);sort($keys);
        if($keys!==['candidate_hash','document_ref','expected_revision','snapshot_ref']
            ||!is_string($p['candidate_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$p['candidate_hash'])
            ||!is_string($p['document_ref'])||!preg_match('/^[A-Za-z0-9_.:-]{3,190}$/D',$p['document_ref'])
            ||!is_string($p['snapshot_ref'])||!preg_match('/^mis-[a-f0-9]{24}$/D',$p['snapshot_ref'])){
            throw new DomainException('Invalid approved settlement references.');
        }
        $id=BudgetControlPolicy::id((string)$action->targetId,'finr-');
        $rev=BudgetControlPolicy::revision($p['expected_revision']);
        $this->admission->assertAuthorized($action);
        $facts=function()use($actor,$id,$p,$action):array{
        $facts=$this->evidence->read($actor,$id,$p['document_ref'],$p['snapshot_ref']);
        if(($facts['document']['document_ref']??null)!==$p['document_ref']
            ||($facts['observation']['source_snapshot_ref']??null)!==$p['snapshot_ref'])
            throw new DomainException('Evidence references changed after approval.');
        $this->admission->assertAuthorized($action);
        return $facts;
        };
        return $this->store->settle($org,$id,$action->sourceId,$action->id,$action->correlationId,$rev,$p['candidate_hash'],$facts);
    }
}
