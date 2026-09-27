<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class HandoffTargetResult
{
    private function __construct(
        public bool $accepted,
        public ?string $referenceType,
        public ?string $referenceId,
        public string $reason,
    ) {
        if(trim($reason)==='')throw new InvalidArgumentException('Growth handoff target result requires a reason.');
        if($accepted){
            if($referenceType===null||trim($referenceType)===''||$referenceId===null||trim($referenceId)===''){
                throw new InvalidArgumentException('Accepted Growth handoff requires target reference type and id.');
            }
        }elseif($referenceType!==null||$referenceId!==null){
            throw new InvalidArgumentException('Rejected Growth handoff must not expose a target reference.');
        }
    }

    public static function accepted(string $referenceType,string $referenceId,string $reason='Accepted by target Domain.'): self
    {
        return new self(true,trim($referenceType),trim($referenceId),trim($reason));
    }

    public static function rejected(string $reason): self
    {
        return new self(false,null,null,trim($reason));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'accepted'=>$this->accepted,
            'reference_type'=>$this->referenceType,
            'reference_id'=>$this->referenceId,
            'reason'=>$this->reason,
        ];
    }
}
