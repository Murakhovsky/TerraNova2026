<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Agent\Service\SensitiveContextRedactor;
use Kernel\Tenant\Model\TenantContext;

/** Native tenant reads only. A context is neither a delegation nor launch authorization. */
final readonly class MarketingAnalystContextService
{
    public function __construct(private MarketingCampaignService $campaigns,private MarketingPlanningService $planning,
        private MarketingProductDraftCheckService $products){}

    /** @return array<string,mixed> */
    public function build(TenantContext $actor,string $campaignId,int $campaignRevision,string $creativeId,int $creativeRevision,
        ?string $adsetId=null,?int $adsetRevision=null):array
    {
        MarketingPlanningPolicy::id($creativeId,'mkcr-');
        MarketingPlanningPolicy::revision($campaignRevision);
        MarketingPlanningPolicy::revision($creativeRevision);
        if(($adsetId===null)!==($adsetRevision===null))throw new DomainException('AdSet pin requires ID and revision.');
        if($adsetId!==null){MarketingPlanningPolicy::id($adsetId,'mkas-');MarketingPlanningPolicy::revision($adsetRevision);}
        $campaign=$this->campaigns->get($actor,$campaignId);
        if($campaign===null||$campaign['status']!=='DRAFT'||$campaign['revision']!==$campaignRevision){
            throw new DomainException('Campaign context is stale or unavailable.');
        }
        $creative=$this->find($this->planning->creatives($actor,$campaignId),$creativeId,$creativeRevision);
        $content=$creative['content'];
        $sources=[
            ['alias'=>'campaign','record_id'=>$campaignId,'revision'=>$campaignRevision],
            ['alias'=>'creative','record_id'=>$creativeId,'revision'=>$creativeRevision,'content_hash'=>$creative['content_hash']]
        ];
        // No owner/user IDs, external account IDs, credentials, URLs, media or raw customer lists.
        $facts=[
            'campaign'=>['objective'=>$this->text($campaign['business_objective'],250)],
            'creative'=>['headline'=>$this->text($content['headline'],180),'primary_text'=>$this->text($content['primary_text'],2500),
                'call_to_action'=>$this->text($content['call_to_action'],80),'language'=>$content['language'],
                'product_verification'=>$content['product_verification'],'destination_verification'=>$content['destination_verification']]
        ];
        if(isset($content['target_spec']))$facts['creative']['target_spec']=$this->target($content['target_spec']);
        $currency=null;
        if($adsetId!==null){
            $adset=$this->find($this->planning->adsets($actor,$campaignId),$adsetId,$adsetRevision);
            $ad=$adset['content'];
            if($adset['pins_stale']||!in_array(['creative_id'=>$creativeId,'revision'=>$creativeRevision],$ad['creatives'],true)){
                throw new DomainException('AdSet context does not pin the current Creative.');
            }
            $audience=$this->find($this->planning->audiences($actor),$ad['audience_id'],$ad['audience_revision']);
            $sources[]=['alias'=>'adset','record_id'=>$adsetId,'revision'=>$adsetRevision,'content_hash'=>$adset['content_hash']];
            $sources[]=['alias'=>'audience','record_id'=>$audience['id'],'revision'=>$audience['revision'],'content_hash'=>$audience['content_hash']];
            $facts['adset']=['optimization'=>$ad['optimization'],'placements'=>$ad['placements'],
                'planned_daily_budget'=>$ad['planned_daily_budget'],'currency'=>$ad['currency'],'finance_reservation_confirmed'=>false];
            $facts['audience']=$this->target($audience['content']['target_spec']);
            $currency=$ad['currency'];
        }
        $facts['product']=['status'=>'NOT_OBSERVED'];
        if($currency!==null&&is_string($content['product_ref']??null)&&preg_match('/^[a-f0-9]{32}$/D',$content['product_ref'])){
            try{
                $product=$this->products->check($actor,$content['product_ref'],$content['variant_ref']??null,$currency,$content['location_ref']??null);
                $facts['product']=['status'=>$product['product_status'],'variant_status'=>$product['variant_status'],
                    'base_price_decimal'=>$product['base_price_decimal'],'currency'=>$currency,'pending_checks'=>$product['pending_checks']];
                if(isset($product['stock_observation']))$facts['product']['stock_observation']=array_intersect_key(
                    $product['stock_observation'],array_flip(['status','on_hand','reserved','available','revision']));
                if($product['can_reference_in_draft']){
                    $sources[]=['alias'=>'product','record_id'=>$product['product_ref'],'revision'=>$product['product_revision']];
                    if($product['variant_ref']!==null)$sources[]=['alias'=>'variant','record_id'=>$product['variant_ref'],'revision'=>$product['variant_revision']];
                }
            }catch(DomainException){
                $facts['product']=['status'=>'UNAVAILABLE'];
            }
        }
        // Provider metrics cannot be attributed to an internal Campaign without a verified binding.
        $facts['performance']=['status'=>'NO_VERIFIED_CAMPAIGN_BINDING'];
        $facts=(new SensitiveContextRedactor())->redact($facts);
        $payload=['schema_version'=>'1.0.0','purpose'=>'MARKETING_PLANNING_ADVICE','sources'=>$sources,'facts'=>$facts,
            'constraints'=>['proposal_only'=>true,'launch_eligible'=>false,'provider_evidence_verified'=>false,'finance_reservation_confirmed'=>false]];
        $encoded=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($encoded)>24000)throw new DomainException('Analyst context exceeds bounded payload.');
        return ['context'=>$payload,'context_hash'=>hash('sha256',$encoded),'delegated'=>false];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function find(array $rows,string $id,int $revision):array
    {
        foreach($rows as $row){
            if($row['id']===$id&&$row['revision']===$revision&&$row['status']==='DRAFT')return $row;
        }
        throw new DomainException('Analyst source revision unavailable or stale.');
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function target(array $target):array
    {
        return ['market_countries'=>$target['market_countries'],'audience_hypothesis'=>$this->text($target['audience_hypothesis'],500),
            'verification_status'=>'UNVERIFIED'];
    }

    private function text(string $value,int $limit):string
    {
        // URLs may contain query credentials; never forward them in free text.
        $value=preg_replace('~(?:https?://|www\\.)[^\\s<>]+~iu','[URL]',$value)??'';
        return mb_substr($value,0,$limit);
    }
}
