<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class AutonomousContentDraft
{
    /** @param list<string> $evidenceIds @param list<string> $riskFlags */
    public function __construct(
        public string $body,
        public array $evidenceIds,
        public array $riskFlags,
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
        if(trim($body)===''||mb_strlen($body)>10000)throw new InvalidArgumentException('Autonomous content draft body is invalid.');
        if($evidenceIds===[])throw new InvalidArgumentException('Autonomous content draft requires evidence.');
        foreach([$evidenceIds,$riskFlags] as $values){
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Autonomous content draft contains an invalid list value.');
            }
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Autonomous content draft confidence must be between 0 and 1.');
        foreach(['provider'=>$provider,'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Autonomous content draft '.$field.' is required.');
        }
    }
}
