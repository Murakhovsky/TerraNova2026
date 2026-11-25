<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingFinanceAnalyticsReadPort;
use Domains\Finance\Application\Contract\FinancePaidExposureClaimStore;
use Domains\Finance\Application\Contract\FinancePaidSettlementStore;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
final readonly class FinanceAnalyticsReadAdapter implements MarketingFinanceAnalyticsReadPort
{
    public function __construct(private FinancePaidExposureClaimStore $claims,private FinancePaidSettlementStore $settlements,private ActiveModuleResolver $modules){}
    public function settlement(TenantContext $actor,string $planId,string $planHash,string $reservationId):?array
    {
        $org=$actor->organizationId()->value();
        if(!$this->modules->isEnabled($org,'finance')||!preg_match('/^finr-[a-f0-9]{24}$/D',$reservationId))throw new DomainException('Finance source unavailable.');
        $c=$this->claims->exposure($org,$reservationId);
        if($c===null||$c['source_ref']!==$planId||$c['plan_hash']!==$planHash)throw new DomainException('Finance claim differs from paid plan.');
        $r=$this->settlements->receipt($org,$reservationId);
        if($r===null)return null;
        if($r['currency']!==$c['currency']||$r['reserved_minor']!==$c['amount_minor']||$r['envelope_id']!==$c['envelope_id']||!$r['finance_settled'])
            throw new DomainException('Finance receipt differs from native exposure.');
        return array_intersect_key($r,array_flip(['reservation_id','action_id','candidate_hash','currency','reserved_minor','committed_minor','released_minor','state','finance_settled']));
    }
}
