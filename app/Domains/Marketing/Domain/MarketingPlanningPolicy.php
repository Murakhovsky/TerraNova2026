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
        $this->only($raw,['headline','primary_text','call_to_action','destination_url','language','product_ref','media_sha256']);
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
        $parts=parse_url($url);
        if($parts===false||strtolower((string)($parts['scheme']??''))!=='https'
            ||!is_string($parts['host']??null)
            ||!preg_match('/^[a-zA-Z0-9.-]{4,253}$/D',$parts['host'])
            ||!str_contains($parts['host'],'.')
            ||str_ends_with(strtolower($parts['host']),'.local')
            ||strtolower($parts['host'])==='localhost'
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])
            ||mb_strlen($url)>1200){
            throw new DomainException('Creative destination requires a bounded public HTTPS hostname without credentials.');
        }
        $productRef=$raw['product_ref']??null;
        if($productRef!==null && (!is_string($productRef)||!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$productRef))){
            throw new DomainException('Invalid unverified Product reference.');
        }
        $media=$raw['media_sha256']??null;
        if($media!==null&&(!is_string($media)||!preg_match('/^[a-f0-9]{64}$/D',$media))){
            throw new DomainException('Creative media must be referenced by a SHA-256 digest, not supplied as raw media.');
        }
        return [
            'headline'=>$headline,'primary_text'=>$text,'call_to_action'=>$cta,'destination_url'=>$url,
            'language'=>$lang,'product_ref'=>$productRef,'media_sha256'=>$media,
            'product_verification'=>'PENDING_PRODUCT_ACCEPTANCE',
            'destination_verification'=>'UNVERIFIED',
            'compliance_state'=>'NOT_REVIEWED',
        ];
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

