<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use Domains\Growth\Domain\GrowthResponseIntent;
use Domains\Growth\Domain\GrowthResponseNextOwner;
use Domains\Growth\Domain\GrowthResponseSentiment;
use Domains\Growth\Domain\GrowthResponseUrgency;
use InvalidArgumentException;

final readonly class GrowthResponseClassificationDraft
{
    public function __construct(
        public GrowthResponseIntent $intent,
        public GrowthResponseSentiment $sentiment,
        public GrowthResponseUrgency $urgency,
        public string $summary,
        public ?string $requestedAction,
        public GrowthResponseNextOwner $recommendedNextOwner,
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
        if(trim($summary)===''||mb_strlen($summary)>1000)throw new InvalidArgumentException('Growth response classification summary is invalid.');
        if($requestedAction!==null&&(trim($requestedAction)===''||mb_strlen($requestedAction)>1000)){
            throw new InvalidArgumentException('Growth response requested action is invalid.');
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Growth response classification confidence is out of range.');
        foreach(['provider'=>$provider,'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth response classification '.$field.' is required.');
        }
    }
}
