<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use Domains\Growth\Domain\IcpCriteria;
use Domains\Growth\Domain\OptimizationTargetType;
use Domains\Growth\Domain\QualificationPolicyCriteria;
use InvalidArgumentException;

final readonly class LearningOptimizationDraft
{
    /**
     * @param array<string,mixed> $proposedCriteria
     * @param list<string> $evidenceIds
     * @param list<string> $risks
     * @param list<string> $assumptions
     */
    public function __construct(
        public OptimizationTargetType $targetType,
        public string $targetId,
        public int $baseRevision,
        public string $proposedName,
        public array $proposedCriteria,
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
            'targetId'=>$targetId,'proposedName'=>$proposedName,'rationale'=>$rationale,
            'provider'=>$provider,'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth optimization '.$field.' is required.');
        }
        if($baseRevision<1)throw new InvalidArgumentException('Growth optimization baseRevision must be positive.');
        if($evidenceIds===[])throw new InvalidArgumentException('Growth optimization requires evidence.');
        foreach([$evidenceIds,$risks,$assumptions] as $values){
            if(!array_is_list($values))throw new InvalidArgumentException('Growth optimization list field must be a list.');
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth optimization list contains invalid value.');
            }
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Growth optimization confidence must be between 0 and 1.');
        self::validateCriteria($targetType,$proposedCriteria);
    }

    /** @param array<string,mixed> $criteria */
    public static function validateCriteria(OptimizationTargetType $targetType,array $criteria):void
    {
        match($targetType){
            OptimizationTargetType::IcpProfile=>IcpCriteria::fromArray($criteria),
            OptimizationTargetType::QualificationPolicy=>QualificationPolicyCriteria::fromArray($criteria),
        };
    }
}
