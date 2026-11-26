<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingFinanceAnalyticsReadPort;
use Domains\Marketing\Domain\MarketingEconomicsPolicy;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingEconomicsService
{
    public function __construct(private MarketingPaidLaunchPlanService $plans,private MarketingAnalyticsService $leads,
        private MarketingCommerceAttributionService $orders,private MarketingFinanceAnalyticsReadPort $finance){}
    public function report(TenantContext $actor,string $planId,?string $reservation,?string $conversionThrough=null):array
    {
        $p=$this->plans->get($actor,$planId);$from=$p['period_start'];$to=$p['period_end'];$campaign=$p['campaign_id'];
        $through=MarketingEconomicsPolicy::conversionThrough($p,$conversionThrough);
        $lead=$this->leads->report($actor,$campaign,$planId,$from,$to,null,null,$through);
        $order=$this->orders->report($actor,$campaign,$planId,$from,$through);
        $settlement=$reservation===null?null:$this->finance->settlement($actor,$planId,$p['plan_hash'],$reservation);
        if($settlement!==null&&($settlement['reservation_id']??null)!==$reservation)throw new DomainException('Settlement reference changed.');
        return MarketingEconomicsPolicy::project($p,$lead,$order,$settlement,$through);
    }
}
