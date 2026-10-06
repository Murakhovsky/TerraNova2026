<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
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
            foreach([[$left,$right],[$right,$left]] as [$underlying,$token]){
                foreach($referenceByInstrument[$underlying]??[] as $reference){
                    foreach($stateByInstrument[$token]??[] as $tokenState){
                        $pairId=$this->pairId('H1',[$reference->sourceId->value(),$underlying,$tokenState->venueId->value(),$token]);
                        $scanOptions=$this->withExecutableHedge($options,$stateByInstrument[$underlying]??[]);
                        try{
                            $h1[]=$this->verticalSlice->scanReference(
                                $organizationId,$pairId,$reference->sourceId->value(),$underlying,
                                $tokenState->venueId->value(),$token,$scanOptions
                            );
                        }catch(\Throwable $error){$errors[]=['hypothesis'=>'H1','market_pair_id'=>$pairId,'error'=>$error->getMessage()];}
                    }
                }
            }

            $combined=[];
            foreach([$left,$right] as $instrument){
                foreach($stateByInstrument[$instrument]??[] as $state)$combined[]=$state;
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
            'relationships_considered'=>count($relationships),
            'market_states_considered'=>count($states),
            'reference_states_considered'=>count($references),
            'h1_scans'=>count($h1),'h2_scans'=>count($h2),
            'h1'=>$h1,'h2'=>$h2,'errors'=>$errors,
        ];
    }

    /** @param array<string,mixed> $options @param list<MarketState> $states @return array<string,mixed> */
    private function withExecutableHedge(array $options,array $states):array
    {
        if(trim((string)($options['hedge_venue_id']??''))!==''&&trim((string)($options['hedge_instrument_id']??''))!==''){
            return $options;
        }
        $eligible=array_values(array_filter(
            $states,
            static fn(mixed $state):bool =>
                $state instanceof MarketState
                &&$state->mode===MarketDataMode::Live
                &&$state->marketStatus===MarketStatus::Open
                &&$state->quality->trusted()
                &&$state->bestQuote!==null
        ));
        if($eligible===[])return $options;
        usort($eligible,static function(MarketState $a,MarketState $b):int{
            $quality=$b->quality->score<=>$a->quality->score;
            if($quality!==0)return $quality;
            $time=$b->updatedAt<=>$a->updatedAt;
            if($time!==0)return $time;
            return strcmp($a->key(),$b->key());
        });
        $hedge=$eligible[0];
        return [
            ...$options,
            'hedge_venue_id'=>$hedge->venueId->value(),
            'hedge_instrument_id'=>$hedge->instrumentId->value(),
        ];
    }

    /** @param list<string> $parts */
    private function pairId(string $hypothesis,array $parts):string
    {
        if($parts===[])throw new InvalidArgumentException('Market pair parts are required.');
        return 'cm_pair_'.strtolower($hypothesis).'_'.substr(hash('sha256',implode('|',$parts)),0,32);
    }
}
