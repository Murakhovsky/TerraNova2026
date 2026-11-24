<?php
declare(strict_types=1);

namespace Domains\Marketing\Domain;

use DomainException;

/**
 * M2 planning data are suggestions, NOT product facts, approved advertisements,
 * external write authority, verified AI output or settled Finance expense.
 */
final class MarketingPlanningPolicy
{
    public static function id(string $value,string $prefix):string
    {
        if(!preg_match('/^'.preg_quote($prefix,'/').'[a-f0-9]{24}$/D',$value)){
            throw new DomainException('Invalid Marketing planning reference.');
        }
        return $value;
    }

    public static function revision(mixed $value):int
    {
        if(!is_int($value)||$value<1||$value>2000000000){
            throw new DomainException('Expected a bounded positive planning revision.');
        }
        return $value;
    }

    public static function key(string $key):string
    {
        if(!preg_match('/^[A-Za-z0-9_.:-]{8,191}$/D',$key)){
            throw new DomainException('A bounded stable planning idempotency key is required.');
        }
        return $key;
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    public function creative(array $raw):array
    {
        $this->only($raw,['headline','primary_text','call_to_action','destination_url','language','product_ref','media_sha256','target_spec','variant_ref','location_ref']);
        foreach(['headline','primary_text','call_to_action','destination_url','language'] as $field){
            if(!is_string($raw[$field]??null)){
                throw new DomainException('Creative planning fields must be strings.');
            }
        }
        $headline=trim($raw['headline']);
        $text=trim($raw['primary_text']);
        $cta=trim($raw['call_to_action']);
        $url=trim($raw['destination_url']);
        $lang=trim($raw['language']);
        if(mb_strlen($headline)<3||mb_strlen($headline)>180
            ||mb_strlen($text)<5||mb_strlen($text)>2500
            ||mb_strlen($cta)<2||mb_strlen($cta)>80
            ||!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$lang)){
            throw new DomainException('Invalid bounded creative text or locale.');
        }
        self::validateDestination($url);
        $productRef=$raw['product_ref']??null;
        if($productRef!==null && (!is_string($productRef)||!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$productRef))){
            throw new DomainException('Invalid unverified Product reference.');
        }
        $media=$raw['media_sha256']??null;
        if($media!==null&&(!is_string($media)||!preg_match('/^[a-f0-9]{64}$/D',$media))){
            throw new DomainException('Creative media must be referenced by a SHA-256 digest, not supplied as raw media.');
        }
        $content = [
            'headline'=>$headline,'primary_text'=>$text,'call_to_action'=>$cta,'destination_url'=>$url,
            'language'=>$lang,'product_ref'=>$productRef,'media_sha256'=>$media,
            'product_verification'=>'PENDING_PRODUCT_ACCEPTANCE',
            'destination_verification'=>'UNVERIFIED',
            'compliance_state'=>'NOT_REVIEWED',
        ];
        if(array_key_exists('variant_ref',$raw)){
            $variant=$raw['variant_ref'];
            if(!is_string($variant)||!preg_match('/^[a-f0-9]{32}$/D',$variant)
                ||!is_string($productRef)||!preg_match('/^[a-f0-9]{32}$/D',$productRef)){
                throw new DomainException('Variant reference requires canonical Product and Variant IDs.');
            }
            $content['variant_ref']=$variant;
        }
        if(array_key_exists('location_ref',$raw)){
            $location=$raw['location_ref'];
            if(!isset($content['variant_ref'])||!is_string($location)||!preg_match('/^[a-f0-9]{32}$/D',$location)){
                throw new DomainException('Location reference requires canonical Variant and Location IDs.');
            }
            $content['location_ref']=$location;
        }
        // Omitted TargetSpec preserves the exact legacy content and idempotency hash.
        if (array_key_exists('target_spec', $raw)) {
            if (!is_array($raw['target_spec'])) throw new DomainException('TargetSpec must be an object.');
            $content['target_spec'] = $this->targetSpec($raw['target_spec']);
        }
        return $content;
    }

    /** Syntactic validation only. Never resolve DNS, follow redirects or fetch user URLs. */
    public static function validateDestination(string $url): void
    {
        if(strlen($url)>1200||preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/',$url)||filter_var($url,FILTER_VALIDATE_URL)===false){
            throw new DomainException('Invalid HTTPS destination syntax.');
        }
        $parts=parse_url($url);
        $host=strtolower((string)($parts['host']??''));
        if($parts===false||strtolower((string)($parts['scheme']??''))!=='https'
            ||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host)
            ||strlen($host)>253||filter_var($host,FILTER_VALIDATE_IP)!==false
            ||preg_match('/\\.(?:localhost|local|internal|test|invalid|onion|lan|home|arpa)$/D',$host)
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])){
            throw new DomainException('Creative destination requires a public-style HTTPS hostname without credentials.');
        }
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    public function audience(array $raw):array
    {
        $this->only($raw,['name','target_spec']);
        $name=$raw['name']??null;
        if(!is_string($name)||mb_strlen(trim($name))<3||mb_strlen(trim($name))>180||!is_array($raw['target_spec']??null)){
            throw new DomainException('Audience requires name and TargetSpec.');
        }
        return ['name'=>trim($name),'target_spec'=>$this->targetSpec($raw['target_spec'])];
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    public function adset(array $raw):array
    {
        $this->only($raw,['name','audience_id','audience_revision','creatives','placements','optimization','planned_daily_budget','currency']);
        $name=$raw['name']??null;$audience=$raw['audience_id']??null;
        $creatives=$raw['creatives']??null;$placements=$raw['placements']??null;
        $optimization=$raw['optimization']??null;$budget=$raw['planned_daily_budget']??null;$currency=$raw['currency']??null;
        if(!is_string($name)||mb_strlen(trim($name))<3||mb_strlen(trim($name))>180||!is_string($audience)
            ||!is_array($creatives)||!array_is_list($creatives)||count($creatives)<1||count($creatives)>20
            ||!is_array($placements)||!array_is_list($placements)||count($placements)<1||count($placements)>5
            ||!in_array($optimization,['REACH','TRAFFIC','LEADS','CONVERSIONS'],true)
            ||!is_string($budget)||!preg_match('/^(0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,2})?$/D',$budget)
            ||!preg_match('/[1-9]/',$budget)||!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)){
            throw new DomainException('Invalid bounded AdSet planning fields.');
        }
        $refs=[];
        foreach($creatives as $item){
            if(!is_array($item))throw new DomainException('Invalid Creative pin.');
            $this->only($item,['creative_id','revision']);
            if(!is_string($item['creative_id']??null))throw new DomainException('Invalid Creative ID.');
            $id=self::id($item['creative_id'],'mkcr-');
            if(isset($refs[$id]))throw new DomainException('Duplicate Creative pin.');
            $refs[$id]=['creative_id'=>$id,'revision'=>self::revision($item['revision']??null)];
        }
        ksort($refs,SORT_STRING);
        foreach($placements as $placement){
            if(!in_array($placement,['AUTOMATIC','FEED','STORIES','REELS','SEARCH'],true))throw new DomainException('Invalid draft placement.');
        }
        if(count(array_unique($placements))!==count($placements)||(in_array('AUTOMATIC',$placements,true)&&count($placements)>1)){
            throw new DomainException('Duplicate or conflicting draft placements.');
        }
        sort($placements,SORT_STRING);
        return ['name'=>trim($name),'audience_id'=>self::id($audience,'mkau-'),
            'audience_revision'=>self::revision($raw['audience_revision']??null),'creatives'=>array_values($refs),
            'placements'=>$placements,'optimization'=>$optimization,'planned_daily_budget'=>$budget,'currency'=>$currency,
            'finance_reservation_confirmed'=>false,'provider_eligible'=>false];
    }

    /** Provider-neutral planning only; no audience IDs, identity lists or activation fields.
     * @param array<string,mixed> $raw @return array<string,mixed>
     */
    private function targetSpec(array $raw): array
    {
        $this->only($raw, ['market_countries', 'audience_hypothesis']);
        $countries = $raw['market_countries'] ?? null;
        $hypothesis = $raw['audience_hypothesis'] ?? null;
        if (!is_array($countries) || !array_is_list($countries) || count($countries) < 1 || count($countries) > 20
            || !is_string($hypothesis) || mb_strlen(trim($hypothesis)) < 5 || mb_strlen(trim($hypothesis)) > 500) {
            throw new DomainException('TargetSpec requires 1-20 market country codes and a 5-500 character audience hypothesis.');
        }
        foreach ($countries as $code) {
            if (!is_string($code) || !preg_match('/^[A-Z]{2}$/D', $code)) {
                throw new DomainException('TargetSpec market codes must be uppercase two-letter codes.');
            }
        }
        if (count(array_unique($countries)) !== count($countries)) {
            throw new DomainException('Duplicate TargetSpec market codes are not allowed.');
        }
        sort($countries, SORT_STRING);
        return ['market_countries'=>$countries, 'audience_hypothesis'=>trim($hypothesis),
            'verification_status'=>'UNVERIFIED', 'provider_eligible'=>false];
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    public function proposal(array $raw):array
    {
        $this->only($raw,['recommendation','rationale','evidence']);
        foreach(['recommendation','rationale'] as $field){
            if(!is_string($raw[$field]??null)||mb_strlen(trim($raw[$field]))<8||mb_strlen(trim($raw[$field]))>4000){
                throw new DomainException('Proposal text must be 8-4000 characters.');
            }
        }
        $items=$raw['evidence']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>10){
            throw new DomainException('Analyst proposal requires 1-10 explicitly identified evidence references.');
        }
        $evidence=[];
        foreach($items as $item){
            if(!is_array($item))throw new DomainException('Evidence item must be an object.');
            $this->only($item,['source_type','reference','note']);
            $kind=$item['source_type']??null;
            $reference=$item['reference']??null;
            $note=$item['note']??null;
            if(!in_array($kind,['MANUAL_NOTE','MARKETING_INSIGHT','PRODUCT_REFERENCE'],true)
                ||!is_string($reference)||!preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$reference)
                ||!is_string($note)||mb_strlen(trim($note))<3||mb_strlen(trim($note))>500){
                throw new DomainException('Untrusted analyst evidence shape rejected.');
            }
            $evidence[]=[
                'source_type'=>$kind,'reference'=>$reference,'note'=>trim($note),
                'verification_status'=>'UNVERIFIED',
            ];
        }
        return [
            'recommendation'=>trim($raw['recommendation']),
            'rationale'=>trim($raw['rationale']),
            'evidence'=>$evidence,
            'origin'=>'HUMAN',
            'provider_evidence_verified'=>false,
        ];
    }

    /** @param array<string,mixed> $raw @return array{decision:string,reason:string} */
    public function review(array $raw):array
    {
        $this->only($raw,['decision','reason']);
        $decision=$raw['decision']??null;$reason=$raw['reason']??null;
        if(!in_array($decision,['ACCEPT','REJECT'],true)||!is_string($reason)
            ||mb_strlen(trim($reason))<5||mb_strlen(trim($reason))>300){
            throw new DomainException('Human review requires ACCEPT/REJECT and a reason of 5-300 characters.');
        }
        return ['decision'=>$decision,'reason'=>trim($reason)];
    }

    /** @param array<string,mixed> $raw @param list<string> $keys */
    private function only(array $raw,array $keys):void
    {
        foreach(array_keys($raw) as $key){
            if(!in_array($key,$keys,true)){
                throw new DomainException('Unknown or privileged Marketing planning field.');
            }
        }
    }
}

