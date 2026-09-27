<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class GrowthConversationRouteRequest
{
    public function __construct(
        public string $organizationId,
        public string $routeId,
        public string $responseId,
        public string $classificationId,
        public string $candidateId,
        public string $recommendationId,
        public ?string $contactId,
        public string $intent,
        public string $urgency,
        public string $summary,
        public string $requestedAction,
        public ?string $candidateTargetDomain,
    ) {
        foreach([
            'organizationId'=>$organizationId,'routeId'=>$routeId,'responseId'=>$responseId,
            'classificationId'=>$classificationId,'candidateId'=>$candidateId,'recommendationId'=>$recommendationId,
            'intent'=>$intent,'urgency'=>$urgency,'summary'=>$summary,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth conversation route '.$field.' is required.');
        }
        if($contactId!==null&&trim($contactId)==='')throw new InvalidArgumentException('Growth conversation route contactId is invalid.');
    }
}
