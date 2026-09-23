<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use Domains\Growth\Domain\ExperimentDecisionType;
use InvalidArgumentException;

final readonly class ExperimentDecisionDraft
{
    /**
     * @param list<string> $evidenceIds
     * @param list<string> $risks
     * @param list<string> $assumptions
     */
    public function __construct(
        public ExperimentDecisionType $decisionType,
        public ?string $promotedVariantKey,
        public string $rationale,
        public array $evidenceIds,
        public array $risks,
        public array $assumptions,
        public float $confidence,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public string $schemaVersion,
        public ?int $inputTokens=null,
        public ?int $outputTokens=null,
        public ?float $costAmount=null,
        public ?string $costCurrency=null,
    ) {
        foreach([
            'rationale'=>$rationale,'provider'=>$provider,'model'=>$model,
            'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth experiment decision '.$field.' is required.');
        }
        if($decisionType===ExperimentDecisionType::PromoteVariant){
            if($promotedVariantKey===null||trim($promotedVariantKey)===''){
                throw new InvalidArgumentException('Growth promote_variant decision requires promotedVariantKey.');
            }
        }elseif($promotedVariantKey!==null){
            throw new InvalidArgumentException('Growth non-promote experiment decision must not select a variant.');
        }
        if($evidenceIds===[])throw new InvalidArgumentException('Growth experiment decision requires evidence.');
        foreach([$evidenceIds,$risks,$assumptions] as $values){
            if(!array_is_list($values))throw new InvalidArgumentException('Growth experiment decision list field is invalid.');
            foreach($values as $value){
                if(!is_string($value)||trim($value)===''){
                    throw new InvalidArgumentException('Growth experiment decision list contains invalid value.');
                }
            }
        }
        if($confidence<0.0||$confidence>1.0){
            throw new InvalidArgumentException('Growth experiment decision confidence must be between 0 and 1.');
        }
    }
}
