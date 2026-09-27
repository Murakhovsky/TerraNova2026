<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;

final readonly class QualificationPolicyCriteria
{
    private const DIMENSIONS=['fit','need','timing','access','value'];

    /**
     * @param array<string,int> $qualifyMinimums
     * @param array<string,int> $hardRejectBelow
     */
    public function __construct(
        public array $qualifyMinimums,
        public array $hardRejectBelow,
        public float $minConfidence,
    ) {
        $this->validateMap($qualifyMinimums,'qualification minimums',true);
        $this->validateMap($hardRejectBelow,'hard reject thresholds',false);
        if($minConfidence<0.0||$minConfidence>1.0)throw new InvalidArgumentException('Growth qualification minConfidence must be between 0 and 1.');

        foreach(self::DIMENSIONS as $dimension){
            if(isset($hardRejectBelow[$dimension])&&$hardRejectBelow[$dimension]>$qualifyMinimums[$dimension]){
                throw new InvalidArgumentException('Growth hard reject threshold cannot exceed qualification minimum for '.$dimension.'.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'qualify_minimums'=>$this->qualifyMinimums,
            'hard_reject_below'=>$this->hardRejectBelow,
            'min_confidence'=>$this->minConfidence,
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        $minimums=$value['qualify_minimums']??null;
        $rejects=$value['hard_reject_below']??[];
        if(!is_array($minimums)||!is_array($rejects))throw new InvalidArgumentException('Growth qualification policy criteria maps are invalid.');
        return new self(
            self::intMap($minimums),
            self::intMap($rejects),
            (float)($value['min_confidence']??0.0),
        );
    }

    /** @param array<string,int> $map */
    private function validateMap(array $map,string $label,bool $requireAll): void
    {
        if($requireAll&&array_diff(self::DIMENSIONS,array_keys($map))!==[]){
            throw new InvalidArgumentException('Growth '.$label.' must define all score dimensions.');
        }
        foreach($map as $dimension=>$score){
            if(!in_array($dimension,self::DIMENSIONS,true)||!is_int($score)||$score<0||$score>100){
                throw new InvalidArgumentException('Growth '.$label.' contains an invalid dimension or score.');
            }
        }
    }

    /** @param array<mixed,mixed> $value @return array<string,int> */
    private static function intMap(array $value): array
    {
        $out=[];
        foreach($value as $key=>$score){
            if(!is_string($key)||!is_int($score))throw new InvalidArgumentException('Growth qualification policy score map is invalid.');
            $out[$key]=$score;
        }
        return $out;
    }
}
