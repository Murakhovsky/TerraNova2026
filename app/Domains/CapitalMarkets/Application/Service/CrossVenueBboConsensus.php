<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Domain\Value\{Decimal,DecimalMath};

/**
 * Read-only fallback recommendation from independent healthy subscriptions
 * of the SAME canonical instrument, SAME quote currency and genuinely
 * LIVE trusted BBO. A similar token symbol is never economic equivalence.
 */
final readonly class CrossVenueBboConsensus
{
    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    public function evaluate(string $instrument,array $candidates,int $maxDeviationBps=30,int $minVenues=2):array
    {
        if($instrument===''||strlen($instrument)>190||$maxDeviationBps<1
           ||$maxDeviationBps>500||$minVenues<2||$minVenues>10
           ||count($candidates)>100){
            throw new DomainException('Invalid bounded reconciliation policy.');
        }
        $trusted=[];$ignored=[];$seenSources=[];$currencies=[];
        $now=time();
        foreach($candidates as $row){
            if(!is_array($row))throw new DomainException('Invalid market evidence row.');
            $id=$row['source_id']??null;
            if(!is_string($id)||$id===''||strlen($id)>190||isset($seenSources[$id])){
                throw new DomainException('Invalid or duplicate reconciliation source.');
            }
            $seenSources[$id]=true;
            $good=($row['instrument_id']??null)===$instrument
                &&($row['data_mode']??null)==='LIVE'
                &&($row['trust_status']??null)==='TRUSTED'
                &&(int)($row['quality_score']??0)>=80
                &&($row['source_enabled']??null)===true
                &&($row['clock_reliable']??null)===true
                &&($row['subscription_active']??null)===true;
            $time=$row['received_at']??null;
            $ts=is_string($time)?strtotime($time.' UTC'):false;
            if($ts===false||$ts<$now-120||$ts>$now+5)$good=false;
            if(!$good){$ignored[]=['source_id'=>$id,'reason'=>'SOURCE_OR_QUALITY_UNTRUSTED'];continue;}
            $venue=$row['venue_id']??null;$currency=$row['quote_asset']??null;
            if(!is_string($venue)||$venue===''||!is_string($currency)||$currency===''){
                $ignored[]=['source_id'=>$id,'reason'=>'VENUE_OR_UNIT_MISSING'];continue;
            }
            try{
                $bid=Decimal::fromString((string)$row['bid_price']);
                $ask=Decimal::fromString((string)$row['ask_price']);
                if(!$bid->isPositive()||!$ask->isPositive()
                    ||$bid->compareTo($ask)>0){
                    throw new DomainException('Crossed quote.');
                }
                $mid=DecimalMath::midpoint($bid,$ask,18);
            }catch(\Throwable){
                $ignored[]=['source_id'=>$id,'reason'=>'PRICE_INVALID'];continue;
            }
            $currencies[$currency]=true;
            $trusted[]=['source_id'=>$id,'venue_id'=>$venue,
                'canonical_event_id'=>$row['canonical_event_id']??null,
                'mid'=>$mid,'bid'=>$bid,'ask'=>$ask,
                'priority'=>(int)($row['source_priority']??100),
                'received_at'=>$time,'quote_asset'=>$currency];
        }
        $ven=array_unique(array_column($trusted,'venue_id'));
        if(count($trusted)<$minVenues||count($ven)<$minVenues){
            return $this->denied('NO_QUORUM',$instrument,$trusted,$ignored);
        }
        if(count($currencies)!==1){
            return $this->denied('CURRENCY_MISMATCH',$instrument,$trusted,$ignored);
        }
        $sorted=$trusted;
        usort($sorted,static fn(array $a,array $b):int=>$a['mid']->compareTo($b['mid']));
        $count=count($sorted);
        $median=$count%2===1?$sorted[intdiv($count,2)]['mid']
            :DecimalMath::midpoint($sorted[intdiv($count,2)-1]['mid'],
                $sorted[intdiv($count,2)]['mid'],18);
        $threshold=Decimal::fromString((string)$maxDeviationBps);
        $readings=[];
        foreach($trusted as $row){
            $bps=DecimalMath::basisPoints(
                DecimalMath::abs(DecimalMath::subtract($row['mid'],$median)),
                $median,8);
            $readings[]=[
                'source_id'=>$row['source_id'],'venue_id'=>$row['venue_id'],
                'canonical_event_id'=>$row['canonical_event_id'],
                'received_at'=>$row['received_at'],
                'mid'=>$row['mid']->value(),'deviation_bps'=>$bps->value(),
                'priority'=>$row['priority'],
            ];
            if($bps->compareTo($threshold)>0){
                return $this->denied('DIVERGED',$instrument,$trusted,$ignored,
                    $readings,$median->value());
            }
        }
        usort($trusted,static fn(array $a,array $b):int=>$a['priority']<=>$b['priority']
            ?:strcmp($a['source_id'],$b['source_id']));
        return [
            'status'=>'CONSENSUS','instrument_id'=>$instrument,
            'quote_asset'=>array_key_first($currencies),'median_price'=>$median->value(),
            'preferred_source_id'=>$trusted[0]['source_id'],
            'backup_source_ids'=>array_values(array_map(static fn(array $r):string=>$r['source_id'],
                array_slice($trusted,1))),
            'evidence'=>$readings,'ignored'=>$ignored,
            'for_execution'=>false,'fallback_mode'=>'RECOMMENDATION_ONLY',
            'cross_instrument_equivalence_established'=>false,
        ];
    }
    private function denied(string $status,string $instrument,array $trusted,array $ignored,
        array $readings=[],?string $median=null):array
    {
        return ['status'=>$status,'instrument_id'=>$instrument,
            'preferred_source_id'=>null,'backup_source_ids'=>[],
            'median_price'=>$median,'evidence'=>$readings,'ignored'=>$ignored,
            'trusted_sources'=>array_column($trusted,'source_id'),
            'for_execution'=>false,'fallback_mode'=>'FAIL_CLOSED',
            'cross_instrument_equivalence_established'=>false];
    }
}
