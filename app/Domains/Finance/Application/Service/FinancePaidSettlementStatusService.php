<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;
use DomainException;
use Domains\Finance\Application\Contract\FinancePaidSettlementStore;
use Domains\Finance\Domain\BudgetControlPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

final readonly class FinancePaidSettlementStatusService
{
    public function __construct(private FinancePaidSettlementReviewService $reviews,private FinancePaidSettlementStore $store,private ActiveModuleResolver $modules){}
    public function status(TenantContext $actor,string $reservation,string $document,string $snapshot):array
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'finance'))throw new DomainException('Finance tenant authority required.');
        BudgetControlPolicy::id($reservation,'finr-');
        $receipt=$this->store->receipt($org,$reservation);
        if($receipt!==null)return ['state'=>'SETTLED','receipt'=>$receipt,'candidate'=>null,'evidence'=>null,'expected_revision'=>null];
        $p=$this->reviews->preview($actor,$reservation,$document,$snapshot);$o=$p['facts']['observation'];$f=$p['file'];
        $evidence=['plan_id'=>$p['facts']['plan']['id'],'document_ref'=>$f['document_ref'],'document_version_ref'=>$f['version_ref'],
            'document_hash'=>$f['sha256'],'snapshot_ref'=>$o['source_snapshot_ref'],'source_hash'=>$o['source_hash'],'source_revision'=>$o['source_revision'],
            'provider_campaign_id'=>$o['external_id'],'window_start'=>$o['window_start'],'window_end'=>$o['window_end'],
            'reported_spend_decimal'=>$o['reported_spend_decimal'],'currency'=>$o['currency']];
        try{$candidate=$this->reviews->read($actor,$reservation,$document,$snapshot)['candidate'];}
        catch(DomainException){$candidate=null;}
        return ['state'=>$candidate===null?'REVIEW_REQUIRED':'MATCHED_NOT_SETTLED','receipt'=>null,'candidate'=>$candidate,
            'evidence'=>$evidence,'expected_revision'=>$p['expected_revision']];
    }
}
