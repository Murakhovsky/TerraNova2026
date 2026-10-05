<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use DomainException;
use InvalidArgumentException;

final class EconomicRelationshipGraph
{
    /** @var array<string,EconomicRelationship> */
    private array $relationships=[];

    public function add(EconomicRelationship $relationship): void
    {
        $key=$relationship->key();
        $existing=$this->relationships[$key]??null;
        if($existing!==null && !$existing->equals($relationship)){
            throw new DomainException('Economic relationship key is already defined with different state.');
        }
        $this->relationships[$key]=$relationship;
    }

    /** @return list<EconomicRelationship> */
    public function all():array{return array_values($this->relationships);}

    /** @return list<EconomicRelationship> */
    public function outgoing(InstrumentId $instrumentId):array
    {
        return array_values(array_filter($this->relationships,
            static fn(EconomicRelationship $r):bool=>$r->sourceInstrument->equals($instrumentId)));
    }

    /** @return list<EconomicRelationship> */
    public function incoming(InstrumentId $instrumentId):array
    {
        return array_values(array_filter($this->relationships,
            static fn(EconomicRelationship $r):bool=>$r->targetInstrument->equals($instrumentId)));
    }

    /** @return list<EconomicRelationship> */
    public function byType(EconomicRelationshipType $type):array
    {
        return array_values(array_filter($this->relationships,
            static fn(EconomicRelationship $r):bool=>$r->type===$type));
    }

    /** @return list<EconomicRelationship> */
    public function between(InstrumentId $left,InstrumentId $right):array
    {
        return array_values(array_filter($this->relationships,static fn(EconomicRelationship $r):bool=>
            ($r->sourceInstrument->equals($left)&&$r->targetInstrument->equals($right))
            ||($r->sourceInstrument->equals($right)&&$r->targetInstrument->equals($left))));
    }

    /** @return list<InstrumentId> */
    public function related(InstrumentId $instrumentId):array
    {
        $related=[];
        foreach([...$this->outgoing($instrumentId),...$this->incoming($instrumentId)] as $relationship){
            $candidate=$relationship->sourceInstrument->equals($instrumentId)
                ?$relationship->targetInstrument:$relationship->sourceInstrument;
            $related[$candidate->value()]=$candidate;
        }
        return array_values($related);
    }

    /** @return list<InstrumentId> */
    public function traverse(InstrumentId $start,int $maxDepth=2):array
    {
        if($maxDepth<1||$maxDepth>5)throw new InvalidArgumentException('Graph traversal depth must be between 1 and 5.');
        $visited=[$start->value()=>true];
        $result=[];
        $queue=[[$start,0]];
        while($queue!==[]){
            [$current,$depth]=array_shift($queue);
            if($depth>=$maxDepth)continue;
            foreach($this->related($current) as $next){
                if(isset($visited[$next->value()]))continue;
                $visited[$next->value()]=true;
                $result[]=$next;
                $queue[]=[$next,$depth+1];
            }
        }
        return $result;
    }
}
