<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;
/** Source-bound cohort economics, not accounting revenue or profitability. */
final class MarketingEconomicsPolicy
{
    public static function project(array $plan,array $lead,array $order,?array $settlement):array
    {
        foreach([$lead,$order] as $r)foreach(['campaign_id'=>'campaign_id','plan_id'=>'id','window_start'=>'period_start','window_end'=>'period_end'] as $key=>$pin)
            if(($r[$key]??null)!==($plan[$pin]??null))throw new DomainException('Economic cohort scope differs from paid plan.');
        $currency=$plan['currency']??null;
        if(!in_array($currency,['USD','EUR','UAH','PLN'],true))throw new DomainException('Unsupported economic currency.');
        $spend=null;$settlementRef=null;
        if($settlement!==null){
            if(($settlement['finance_settled']??null)!==true||($settlement['state']??null)!=='SETTLED'||($settlement['currency']??null)!==$currency
                ||!is_int($settlement['committed_minor']??null)||$settlement['committed_minor']<0
                ||($settlement['reserved_minor']??null)!==($plan['lifetime_minor']??null)
                ||!is_int($settlement['released_minor']??null)||$settlement['released_minor']<0
                ||$settlement['committed_minor']+$settlement['released_minor']!==$settlement['reserved_minor']
                ||!is_string($settlement['reservation_id']??null)||!preg_match('/^finr-[a-f0-9]{24}$/D',$settlement['reservation_id']))
                throw new DomainException('Finance settlement scope/arithmetic invalid.');
            $spend=$settlement['committed_minor'];$settlementRef=$settlement['reservation_id'];
        }
        foreach(['leads','qualified_leads','won_deals','missing_source_count','source_drift_count'] as $k)
            if(!is_int($lead[$k]??null)||$lead[$k]<0)throw new DomainException('Malformed lead cohort.');
        foreach(['placed_orders','unresolved_captured_orders'] as $k)
            if(!is_int($order[$k]??null)||$order[$k]<0)throw new DomainException('Malformed order cohort.');
        $leadSafe=$lead['missing_source_count']===0&&$lead['source_drift_count']===0;
        $orderSafe=$order['unresolved_captured_orders']===0;
        $money=null;$financeComplete=false;
        if($orderSafe&&($order['financial_evidence_status']??null)==='COMPLETE_CAPTURED_COHORT'
            &&$order['placed_orders']>0&&($order['financial_evidence_count']??null)===$order['placed_orders']
            &&($order['financial_evidence_invalid_count']??null)===0&&is_array($order['net_cash_received']??null)
            &&count($order['net_cash_received'])===1&&($order['net_cash_received'][0]['currency']??null)===$currency
            &&is_int($order['net_cash_received'][0]['minor']??null)&&$order['net_cash_received'][0]['minor']>=0){
            $money=$order['net_cash_received'][0]['minor'];$financeComplete=true;
        }
        $cost=static fn(int $n,bool $safe):?float=>$spend===null||$n===0||!$safe?null:round($spend/$n/100,4);
        return ['campaign_id'=>$plan['campaign_id'],'plan_id'=>$plan['id'],'window_start'=>$plan['period_start'],'window_end'=>$plan['period_end'],
            'currency'=>$currency,'coverage'=>'CAPTURED_COHORT_ONLY','spend_basis'=>$spend===null?'UNAVAILABLE':'FINANCE_SETTLED_MEDIA_BUDGET',
            'settlement_ref'=>$settlementRef,'settled_media_minor'=>$spend,'captured_leads'=>$lead['leads'],'qualified_leads'=>$lead['qualified_leads'],
            'won_deals'=>$lead['won_deals'],'placed_orders'=>$order['placed_orders'],'lead_evidence_clean'=>$leadSafe,'order_evidence_clean'=>$orderSafe,
            'cost_per_captured_lead'=>$cost($lead['leads'],$leadSafe),'cost_per_captured_qualified_lead'=>$cost($lead['qualified_leads'],$leadSafe),
            'cost_per_captured_won_deal'=>$cost($lead['won_deals'],$leadSafe),'cost_per_captured_placed_order'=>$cost($order['placed_orders'],$orderSafe),
            'confirmed_collection_minor'=>$money,'collection_scope_complete'=>$financeComplete,
            'net_collected_to_settled_media_spend'=>$money===null||$spend===null||$spend===0?null:round($money/$spend,4),
            'collection_basis'=>'CURRENT_READBACK_OF_PLACEMENT_COHORT','cac'=>null,'roas'=>null,'romi'=>null,'profit'=>null,
            'recognized_revenue'=>null,'causal_attribution'=>false,'complete_coverage'=>false];
    }
}
