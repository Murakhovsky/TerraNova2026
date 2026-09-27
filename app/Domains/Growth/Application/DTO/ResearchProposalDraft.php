<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class ResearchProposalDraft
{
    /**
     * @param list<string> $evidenceIds
     * @param list<string> $counterEvidenceIds
     * @param list<string> $assumptions
     * @param list<string> $unknowns
     */
    public function __construct(
        public string $whyItMatters,
        public string $problemHypothesis,
        public string $whyNow,
        public array $evidenceIds,
        public array $counterEvidenceIds,
        public array $assumptions,
        public array $unknowns,
        public float $confidence,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public string $schemaVersion,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?float $costAmount = null,
        public ?string $costCurrency = null,
    ) {
        foreach([
            'whyItMatters'=>$whyItMatters,'problemHypothesis'=>$problemHypothesis,'whyNow'=>$whyNow,
            'provider'=>$provider,'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth research draft '.$field.' is required.');
        }
        if($evidenceIds===[])throw new InvalidArgumentException('Growth research draft requires supporting evidence.');
        foreach([$evidenceIds,$counterEvidenceIds,$assumptions,$unknowns] as $values){
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth research draft contains an invalid list value.');
            }
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Growth research draft confidence must be between 0 and 1.');
    }
}
