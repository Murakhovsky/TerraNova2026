<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class GrowthConversationRoutingTargetResult
{
    private function __construct(
        public bool $accepted,
        public ?string $referenceType,
        public ?string $referenceId,
        public string $reason,
    ) {
        if(trim($reason)==='')throw new InvalidArgumentException('Growth conversation routing target result requires a reason.');
        if($accepted){
            if($referenceType===null||trim($referenceType)===''||$referenceId===null||trim($referenceId)===''){
                throw new InvalidArgumentException('Accepted Growth conversation route requires a target reference.');
            }
        }elseif($referenceType!==null||$referenceId!==null){
            throw new InvalidArgumentException('Rejected Growth conversation route must not expose a target reference.');
        }
    }

    public static function accepted(string $referenceType,string $referenceId,string $reason):self
    {
        return new self(true,trim($referenceType),trim($referenceId),trim($reason));
    }

    public static function rejected(string $reason):self
    {
        return new self(false,null,null,trim($reason));
    }
}
