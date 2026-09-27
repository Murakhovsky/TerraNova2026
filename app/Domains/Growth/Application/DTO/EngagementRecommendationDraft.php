<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\NextBestActionType;
use InvalidArgumentException;

final readonly class EngagementRecommendationDraft
{
    /**
     * @param list<string> $evidenceIds
     * @param list<string> $unknowns
     */
    public function __construct(
        public NextBestActionType $actionType,
        public EngagementChannel $channel,
        public ?string $contactId,
        public string $rationale,
        public string $messageAngle,
        public array $evidenceIds,
        public array $unknowns,
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
        if($contactId!==null&&trim($contactId)==='')throw new InvalidArgumentException('Growth engagement contactId must be null or non-empty.');
        foreach(['rationale'=>$rationale,'messageAngle'=>$messageAngle,'provider'=>$provider,'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth engagement '.$field.' is required.');
        }
        if($evidenceIds===[])throw new InvalidArgumentException('Growth engagement recommendation requires evidence.');
        foreach([$evidenceIds,$unknowns] as $values){
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth engagement recommendation contains an invalid list value.');
            }
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Growth engagement confidence must be between 0 and 1.');
    }
}
