<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;
use DateTimeImmutable;
use DateTimeZone;
final class MarketingCommerceAttributionPolicy
{
    public const MODEL='commerce-utm-v1';
    public static function time(mixed $s):DateTimeImmutable
    {
        if(!is_string($s)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D',$s))throw new DomainException('Invalid UTC order fact time.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$s,new DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d\TH:i:s.u\Z')!==$s)throw new DomainException('Invalid order fact date.');
        return $d;
    }
    public static function evidence(array $source,string $org,string $order,string $campaign,?array $plan):array
    {
        $p=$source['placement']??null;
        if(!is_array($p)||($p['organization_id']??null)!==$org||($p['order_id']??null)!==$order
            ||!preg_match('/^[a-f0-9]{32}$/D',$order)||!preg_match('/^mkt-[a-f0-9]{24}$/D',$campaign)
            ||($p['event_type']??null)!=='sales.commerce.order.placed'||($p['schema_version']??null)!=='1.0.0'
            ||($p['revision']??null)!==1||!is_string($p['event_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$p['event_id'])
            ||($p['attribution_status']??null)!=='unresolved'||!array_key_exists('realized_revenue',$p)||$p['realized_revenue']!==null
            ||!array_key_exists('financial_evidence_reference',$p)||$p['financial_evidence_reference']!==null)
            throw new DomainException('Unverified native placement fact.');
        $placed=self::time($p['occurred_at']??null);
        $currency=$p['currency']??null;$total=$p['order_total']??null;
        if(!in_array($currency,['USD','EUR','UAH'],true)||!is_string($total)||!preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$total))
            throw new DomainException('Unusable exact commercial value.');
        $visit=null;$sourceHash=null;
        if(($p['channel']??null)==='shopify'&&($p['provenance']??null)==='shopify_admin_read'){
            $j=$source['journey']??null;
            if(!is_array($j)||($j['state']??null)!=='observed'||($j['model']??null)!=='shopify-last-visit-utm-v1'
                ||!is_string($j['evidence_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$j['evidence_hash']))
                throw new DomainException('Shopify journey absent, not ready or conflicting.');
            $visit=self::time($j['visit_at']??null);
            if($visit>$placed||$placed>$visit->modify('+30 days'))throw new DomainException('Shopify visit outside 30-day attribution window.');
            $tags=$j;$sourceHash=$j['evidence_hash'];$basis='PROVIDER_LAST_VISIT_UTM';
        }elseif(($p['channel']??null)==='native'&&($p['provenance']??null)==='native_operator'){
            $tags=(array)($p['attribution_observations']??[]);$basis='OPERATOR_RECORDED_UTM';
        }else throw new DomainException('Unsupported or untrusted commerce attribution source.');
        $planId=$plan['id']??null;$tag=$planId??$campaign;
        if($plan!==null&&(($plan['campaign_id']??null)!==$campaign||!is_string($planId)||!preg_match('/^mkpl-[a-f0-9]{24}$/D',$planId)))
            throw new DomainException('Paid plan scope differs.');
        if(($tags['utm_campaign']??null)!==$tag||isset($tags['campaign_id'])&&$tags['campaign_id']!==$campaign&&$tags['campaign_id']!==$tag)
            throw new DomainException('Exact campaign tag required.');
        foreach(['utm_source','utm_medium'] as $k)if(!is_string($tags[$k]??null)||!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$tags[$k]))throw new DomainException('Canonical UTM required.');
        if($plan!==null){
            $date=($visit??$placed)->format('Y-m-d');
            if($tags['utm_source']!=='meta'||$tags['utm_medium']!=='paid_social'||$date<$plan['period_start']||$date>$plan['period_end'])
                throw new DomainException('Paid plan visit/tag scope differs.');
        }
        $e=['model'=>self::MODEL,'order_id'=>$order,'placement_event_id'=>$p['event_id'],'plan_id'=>$planId,
            'occurred_at'=>$placed->format('Y-m-d\TH:i:s.u\Z'),'channel'=>$p['channel'],'basis'=>$basis,
            'journey_hash'=>$sourceHash,'visit_at'=>$visit?->format('Y-m-d\TH:i:s.u\Z'),
            'utm_source'=>$tags['utm_source'],'utm_medium'=>$tags['utm_medium'],'utm_campaign'=>$tag,
            'currency'=>$currency,'placed_total_minor'=>(int)str_replace('.','',$total)];
        return [...$e,'evidence_hash'=>hash('sha256',json_encode($e,JSON_THROW_ON_ERROR))];
    }
}
