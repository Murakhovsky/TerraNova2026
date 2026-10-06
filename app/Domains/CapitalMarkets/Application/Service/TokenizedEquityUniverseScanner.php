<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\Opportunity\TokenizedEquityUniverse;
use InvalidArgumentException;

final readonly class TokenizedEquityUniverseScanner
{
    public function __construct(
        private MarketStateRepositoryInterface $marketStates,
        private RelationshipRepository $relationships,
        private TokenizedEquityVerticalSliceService $verticalSlice,
    ){}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function scan(string $organizationId,array $options):array
    {
        $universePayload=$options['universe']??[];
        if(!is_array($universePayload)||array_is_list($universePayload))throw new InvalidArgumentException('universe must be an object.');
        $universe=TokenizedEquityUniverse::fromArray($universePayload);
        if(!$universe->enabled||$universe->status!=='ACTIVE')return [
            'universe'=>['id'=>$universe->id,'name'=>$universe->name,'status'=>$universe->status,'enabled'=>$universe->enabled],
            'relationships_considered'=>0,'market_states_considered'=>0,'reference_states_considered'=>0,
            'h1_scans'=>0,'h2_scans'=>0,'h1'=>[],'h2'=>[],'errors'=>[],
        ];
        $states=$this->marketStates->list($organizationId,1000);
        $references=$this->marketStates->listReferences($organizationId,1000);
        $relationships=$this->relationships->list($organizationId,500);
        $stateByInstrument=[];
        foreach($states as $state)$stateByInstrument[$state->instrumentId->value()][]=$state;
        $referenceByInstrument=[];
        foreach($references as $reference)$referenceByInstrument[$reference->instrumentId->value()][]=$reference;

        $h1=[];$h2=[];$errors=[];
        foreach($relationships as $relationship){
            if(!$relationship instanceof EconomicRelationship||$relationship->status!==EconomicRelationshipStatus::Active)continue;
            $left=$relationship->sourceInstrument->value();
            $right=$relationship->targetInstrument->value();
            if($universe->allowsHypothesis('H1'))foreach([[$left,$right],[$right,$left]] as [$underlying,$token]){
                if(!$universe->allowsUnderlying($underlying)||!$universe->allowsToken($token))continue;
                foreach($referenceByInstrument[$underlying]??[] as $reference){
                    foreach($stateByInstrument[$token]??[] as $tokenState){
                        if(!$universe->allowsVenue($tokenState->venueId->value()))continue;
                        $pairId=$this->pairId('H1',[$reference->sourceId->value(),$underlying,$tokenState->venueId->value(),$token]);
                        try{
                            $h1[]=$this->verticalSlice->scanReference(
                                $organizationId,$pairId,$reference->sourceId->value(),$underlying,
                                $tokenState->venueId->value(),$token,$options
                            );
                        }catch(\Throwable $error){$errors[]=['hypothesis'=>'H1','market_pair_id'=>$pairId,'error'=>$error->getMessage()];}
                    }
                }
            }

            if(!$universe->allowsHypothesis('H2'))continue;
            $combined=[];
            foreach([$left,$right] as $instrument){
                if(!$universe->allowsToken($instrument))continue;
                foreach($stateByInstrument[$instrument]??[] as $state){
                    if($universe->allowsVenue($state->venueId->value()))$combined[]=$state;
                }
            }
            $count=count($combined);
            for($i=0;$i<$count;$i++){
                for($j=$i+1;$j<$count;$j++){
                    $a=$combined[$i];$b=$combined[$j];
                    if(!$a instanceof MarketState||!$b instanceof MarketState)continue;
                    if($a->venueId->equals($b->venueId))continue;
                    $pairId=$this->pairId('H2',[
                        $a->venueId->value(),$a->instrumentId->value(),$b->venueId->value(),$b->instrumentId->value()
                    ]);
                    try{
                        $h2[]=$this->verticalSlice->scanCrossVenue(
                            $organizationId,$pairId,$a->venueId->value(),$a->instrumentId->value(),
                            $b->venueId->value(),$b->instrumentId->value(),$options
                        );
                    }catch(\Throwable $error){$errors[]=['hypothesis'=>'H2','market_pair_id'=>$pairId,'error'=>$error->getMessage()];}
                }
            }
        }

        return [
            'universe'=>[
                'id'=>$universe->id,'name'=>$universe->name,'status'=>$universe->status,'enabled'=>$universe->enabled,
                'underlying_instrument_ids'=>$universe->underlyingInstrumentIds,
                'tokenized_instrument_ids'=>$universe->tokenizedInstrumentIds,
                'venues'=>$universe->venues,'hypotheses'=>$universe->hypotheses,
            ],
            'relationships_considered'=>count($relationships),
            'market_states_considered'=>count($states),
            'reference_states_considered'=>count($references),
            'h1_scans'=>count($h1),'h2_scans'=>count($h2),
            'h1'=>$h1,'h2'=>$h2,'errors'=>$errors,
        ];
    }

    /** @param list<string> $parts */
    private function pairId(string $hypothesis,array $parts):string
    {
        if($parts===[])throw new InvalidArgumentException('Market pair parts are required.');
        return 'cm_pair_'.strtolower($hypothesis).'_'.substr(hash('sha256',implode('|',$parts)),0,32);
    }
}
