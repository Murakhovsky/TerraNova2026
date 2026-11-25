<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DomainException;

/** Untrusted external responses may only propose, never authorize an Action. */
final class MarketingAnalystRecommendationPolicy
{
    /** @param array<string,mixed> $envelope @param array<string,mixed> $raw @return array<string,mixed> */
    public function validate(array $envelope,array $raw):array
    {
        $keys=array_keys($raw);sort($keys);
        if($keys!==['context_hash','evidence_aliases','rationale','recommendation']
            ||!is_string($raw['context_hash'])||!hash_equals($envelope['context_hash'],$raw['context_hash'])){
            throw new DomainException('Analyst output does not match the scoped context contract.');
        }
        foreach(['recommendation','rationale'] as $field){
            if(!is_string($raw[$field])||mb_strlen(trim($raw[$field]))<8||mb_strlen(trim($raw[$field]))>2000){
                throw new DomainException('Invalid bounded analyst text.');
            }
        }
        $aliases=$raw['evidence_aliases'];
        if(!is_array($aliases)||!array_is_list($aliases)||count($aliases)<1||count($aliases)>6){
            throw new DomainException('Analyst output needs scoped evidence aliases.');
        }
        $allowed=array_column($envelope['context']['sources'],'alias');$seen=[];
        foreach($aliases as $alias){
            if(!is_string($alias)||!in_array($alias,$allowed,true)||isset($seen[$alias])){
                throw new DomainException('Unknown or duplicate analyst evidence alias.');
            }
            $seen[$alias]=true;
        }
        $evidence=array_values(array_filter($envelope['context']['sources'],static fn(array $source):bool=>isset($seen[$source['alias']])));
        return ['context_hash'=>$raw['context_hash'],'recommendation'=>trim($raw['recommendation']),'rationale'=>trim($raw['rationale']),
            'evidence'=>$evidence,'status'=>'UNREVIEWED_ADVICE','paid_activation_approved'=>false,'provider_evidence_verified'=>false];
    }

    /** @return array<string,mixed> */
    public static function responseSchema():array
    {
        return ['type'=>'object','additionalProperties'=>false,'required'=>['context_hash','recommendation','rationale','evidence_aliases'],
            'properties'=>['context_hash'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                'recommendation'=>['type'=>'string','minLength'=>8,'maxLength'=>2000],
                'rationale'=>['type'=>'string','minLength'=>8,'maxLength'=>2000],
                'evidence_aliases'=>['type'=>'array','minItems'=>1,'maxItems'=>6,'items'=>['type'=>'string']]]];
    }
}
