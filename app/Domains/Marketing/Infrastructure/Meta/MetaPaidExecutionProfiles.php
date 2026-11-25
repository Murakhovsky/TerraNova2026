<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

use DomainException;
use Domains\Marketing\Domain\MarketingPaidLifecyclePolicy;

/** Deployment-only registry, disabled by default. Evidence references are operator attestations, not provider proof. */
final readonly class MetaPaidExecutionProfiles
{
    public function __construct(private string $profilesJson='{}',private bool $activationEnabled=false){}

    /** A local configuration fingerprint, not remote acceptance or execution authority. */
    public function readiness(string $org,array $plan):array
    {
        $result=['configuration_hash'=>null,'profile_configured'=>false,'activation_configured'=>false,'provider_cap_verified'=>false,'special_ad_categories'=>[],'special_ad_category_country'=>[]];
        try{
            if(strlen($this->profilesJson)>65536)return $result;
            $all=json_decode($this->profilesJson,true,16,JSON_THROW_ON_ERROR);$p=$all[$org][$plan['id']]??null;
            if(!is_array($p)||array_is_list($p))return $result;
            ksort($p);$hash=hash('sha256',json_encode([$org,$plan['id'],$p],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $accepted=$this->accepted($org,$plan,$hash);
            return [...$result,'configuration_hash'=>$hash,'profile_configured'=>true,'activation_configured'=>$this->activationEnabled,
                'special_ad_categories'=>$accepted['special_ad_categories'],'special_ad_category_country'=>$accepted['special_ad_category_country']];
        }catch(\Throwable){return $result;}
    }

    public function accepted(string $org,array $plan,string $hash,bool $activation=false):array
    {
        if(strlen($this->profilesJson)>65536)throw new DomainException('Meta profile registry too large.');
        try{$all=json_decode($this->profilesJson,true,16,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new DomainException('Invalid Meta profile registry.');}
        $p=$all[$org][$plan['id']]??null;
        if(!is_array($p)||array_is_list($p))throw new DomainException('No accepted deployment profile for this exact plan.');
        $keys=array_keys($p);sort($keys);
        $general=['accepted_until','account_id','billing_cap_evidence_ref','creative_hash','creative_id','credential_ref',
            'exclusive_control_evidence_ref','general_retail_review_ref','plan_hash','provider_account_id'];
        $special=['accepted_until','account_id','billing_cap_evidence_ref','creative_hash','creative_id','credential_ref',
            'exclusive_control_evidence_ref','plan_hash','provider_account_id','special_ad_categories','special_ad_category_country','special_category_review_ref'];
        if($keys!==$general&&$keys!==$special){
            throw new DomainException('Unexpected Meta deployment profile fields.');
        }
        $categories=$p['special_ad_categories']??[];$countries=$p['special_ad_category_country']??[];
        if(!is_array($categories)||!is_array($countries)||($keys===$special&&$categories===[]))throw new DomainException('Invalid special profile.');
        MarketingPaidLifecyclePolicy::specialScope($categories,$countries);
        foreach($p as $field=>$v){if(in_array($field,['special_ad_categories','special_ad_category_country'],true))continue;if(!is_string($v)||strlen($v)>190)throw new DomainException('Invalid Meta deployment profile.');}
        if($p['account_id']!==$plan['account_id']||$p['plan_hash']!==$plan['plan_hash']
            ||!preg_match('/^act_[0-9]{1,30}$/D',$p['provider_account_id'])
            ||!preg_match('/^[0-9]{1,30}$/D',$p['creative_id'])
            ||!preg_match('/^[a-f0-9]{64}$/D',$p['creative_hash'])
            ||!preg_match('/^env:\\/\\/[A-Z][A-Z0-9_]{2,127}$/D',$p['credential_ref'])){
            throw new DomainException('Meta profile does not match pinned plan.');
        }
        foreach(['billing_cap_evidence_ref','exclusive_control_evidence_ref',$keys===$special?'special_category_review_ref':'general_retail_review_ref'] as $field){
            if(!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$p[$field]))throw new DomainException('Independent operator evidence reference required.');
        }
        $until=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z',$p['accepted_until'],new \DateTimeZone('UTC'));
        if(!$until||$until->format('Y-m-d\\TH:i:s\\Z')!==$p['accepted_until']||$until->getTimestamp()<=time()
            ||$until->getTimestamp()>time()+30*86400)throw new DomainException('Meta profile acceptance expired or unbounded.');
        ksort($p);
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals(hash('sha256',json_encode([$org,$plan['id'],$p],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),$hash))
            throw new DomainException('Approved Meta execution configuration changed.');
        if($activation&&!$this->activationEnabled)throw new DomainException('Meta activation rollout is disabled.');
        return [...$p,'special_ad_categories'=>$categories,'special_ad_category_country'=>$countries];
    }
}
