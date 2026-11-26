<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;

use DomainException;
use Domains\Finance\Application\Contract\MarketingSpendEvidenceReadPort;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Domains\Finance\Application\Contract\FinanceMarketingSpendActionIntentRepositoryInterface;

/**
 * Accessible only to the Finance canonical Action handler. The Kernel Approval
 * is the execution authority. Evidence never becomes a financial Expense.
 */
final readonly class ApprovedFinanceMarketingSpendImportOperations
{
    public function __construct(
        private MarketingSpendEvidenceReadPort $source,
        private FinanceSpendObservationService $finance,
        private FinanceMarketingSpendActionIntentRepositoryInterface $intents,
        private WorkflowActionWorkerAdmissionInterface $admission,
        private ActiveModuleResolver $modules
    ){}

    /** @return array<string,mixed> */
    public function execute(Action $action,TenantContext $actor):array
    {
        $organizationId=$actor->organizationId()->value();
        $parameters=$action->parameters;
        $keys=array_keys($parameters);sort($keys);
        if($action->type!=='finance.ad_spend.marketing_snapshot.import'||$action->status!==ActionStatus::Running
            ||$action->sourceType!=='USER'||$action->sourceId!==$actor->userId()->value()
            ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1||$action->organizationId!==$organizationId
            ||$action->executionMode!=='APPROVAL_REQUIRED'||$action->targetType!=='marketing_spend_snapshot'
            ||!preg_match('/^wf:[a-f0-9]{64}$/D',(string)$action->idempotencyKey)
            ||!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($organizationId,'finance')||!$this->modules->isEnabled($organizationId,'marketing')
            ||$keys!==['ad_account_ref','expected_source_hash','expected_source_revision','source_snapshot_ref']
            ||!is_string($parameters['ad_account_ref'])||!is_string($parameters['source_snapshot_ref'])
            ||!is_int($parameters['expected_source_revision'])||!is_string($parameters['expected_source_hash'])
            ||$parameters['source_snapshot_ref']!==$action->targetId){
            throw new DomainException('Canonical approved Finance Workflow and active tenant authority required.');
        }
        $adAccountRef=$parameters['ad_account_ref'];$snapshotRef=$parameters['source_snapshot_ref'];
        $expectedRevision=$parameters['expected_source_revision'];$expectedHash=$parameters['expected_source_hash'];
        $idempotencyKey=(string)$action->idempotencyKey;$correlationId=$action->correlationId;
        $this->admission->assertAuthorized($action);
        if($organizationId===''||strlen($organizationId)>190
            ||!preg_match('/^mads-[a-f0-9]{24}$/D',$adAccountRef)
            ||!preg_match('/^mis-[a-f0-9]{24}$/D',$snapshotRef)
            ||$expectedRevision<1||$expectedRevision>2000000000
            ||!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)
            ||!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$correlationId)){
            throw new DomainException('Approved Finance evidence Action lacks a fixed source identity.');
        }

        // Claim FIRST. A completed Action must replay its original immutable
        // receipt even when Marketing has since observed a newer source revision.
        $prior=$this->intents->claim(
            $organizationId,$idempotencyKey,$adAccountRef,$snapshotRef,$expectedRevision,$expectedHash
        );
        if($prior['state']==='SUCCEEDED'){
            if(!is_string($prior['finance_observation_id'])){
                throw new DomainException('Completed Finance Action has no persisted observation receipt.');
            }
            return $this->result($prior['finance_observation_id'],$snapshotRef,$expectedRevision,true);
        }

        $evidence=$this->source->get($actor,$adAccountRef,$snapshotRef);
        $fields=['ad_account_ref','source_snapshot_ref','grain','external_id','window_start','window_end',
            'timezone','currency','attribution_hash','source_hash','source_revision',
            'source_status','reported_spend_decimal'];
        if(array_keys($evidence)!==$fields
            ||$evidence['ad_account_ref']!==$adAccountRef
            ||$evidence['source_snapshot_ref']!==$snapshotRef
            ||$evidence['grain']!=='campaign'
            ||$evidence['source_revision']!==$expectedRevision
            ||$evidence['source_hash']!==$expectedHash){
            // An approval for an earlier Meta version cannot silently authorize
            // a corrected source amount or attribution.
            throw new DomainException('Marketing source changed after Finance independent approval; new Action required.');
        }

        // Revalidate the persisted approved claim immediately before the native write.
        $this->admission->assertAuthorized($action);
        $record=$this->finance->acceptUnverifiedMarketingSnapshot($organizationId,$evidence,$correlationId);
        if(!is_string($record['id']??null)||!is_int($record['source_revision']??null)
            ||$record['source_revision']!==$expectedRevision
            ||($record['source_hash']??null)!==$expectedHash
            ||($record['evidence_verification']??null)!=='UNVERIFIED_SOURCE'
            ||($record['finance_expense_confirmed']??true)!==false){
            throw new DomainException('Finance failed to confirm the exact source-only observation receipt.');
        }
        $this->intents->complete($organizationId,$idempotencyKey,(string)$record['id'],$expectedRevision,$expectedHash);
        return $this->result((string)$record['id'],$snapshotRef,$expectedRevision,false);
    }

    /** @return array<string,mixed> */
    private function result(string $observationId,string $snapshotRef,int $revision,bool $replayed):array
    {
        return [
            'status'=>'SOURCE_OBSERVATION_UNRECONCILED',
            'finance_observation_ref'=>$observationId,
            'source_snapshot_ref'=>$snapshotRef,
            'source_revision'=>$revision,
            'idempotent_replay'=>$replayed,
            'finance_expense_confirmed'=>false,
            'budget_mutated'=>false,
            'source_verified_by_provider'=>false
        ];
    }
}
