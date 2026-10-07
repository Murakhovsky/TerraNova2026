<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\ResearchReplayAdapterInterface;
use Domains\CapitalMarkets\Domain\Instrument\SpotProfile;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateType;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\Opportunity\RelativeValueEvaluation;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Research\ReplayDataGuard;
use Domains\CapitalMarkets\Domain\Research\MarketRegimeClassifier;
use Domains\CapitalMarkets\Domain\Service\RelativeValueEconomicsCalculator;
use Domains\CapitalMarkets\Domain\Service\RelativeValueOpportunityEvaluator;
use Domains\CapitalMarkets\Domain\Service\SpotPerpetualMarketStateFactory;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final readonly class RelativeValueHistoricalReplayService implements ResearchReplayAdapterInterface
{
    public function __construct(
        private MarketSnapshotRepositoryInterface $snapshots,
        private SpotPerpetualMarketStateFactory $stateFactory,
        private RelativeValueEconomicsCalculator $economics,
        private RelativeValueOpportunityEvaluator $evaluator,
        private ReplayDataGuard $guard,
        private MarketRegimeClassifier $regimes,
    ){}

    public function supports(string $hypothesisCode):bool
    {
        return in_array(strtoupper(trim($hypothesisCode)),['H4','H5','H6'],true);
    }

    public function replay(string $organizationId,string $hypothesisCode,array $configuration):array
    {
        $code=strtoupper(trim($hypothesisCode));
        if(!$this->supports($code))throw new InvalidArgumentException('Relative-value replay supports H4/H5/H6 only.');
        $this->guard->assertTransactionCosts($configuration);

        $from=new DateTimeImmutable((string)($configuration['from']??'1970-01-01T00:00:00Z'));
        $to=new DateTimeImmutable((string)($configuration['to']??'9999-12-31T23:59:59Z'));
        if($to<=$from)throw new InvalidArgumentException('Replay period is invalid.');

        $rows=[];$skipped=0;
        foreach($this->snapshots->list($organizationId,min(10000,max(1,(int)($configuration['snapshot_limit']??5000)))) as $snapshot){
            if(!$snapshot instanceof MarketSnapshot)continue;
            if($snapshot->createdAt<$from||$snapshot->createdAt>$to)continue;
            try{
                $row=$code==='H6'
                    ? $this->replayCrossVenue($snapshot,$configuration)
                    : $this->replaySpotPerp($snapshot,$code,$configuration);
                if($row!==null)$rows[]=$row;else $skipped++;
            }catch(DomainException|InvalidArgumentException){
                $skipped++;
            }
        }

        $positive=array_values(array_filter($rows,static fn(array $r):bool=>
            Decimal::fromString((string)($r['expected_net_pnl']??'0'))->isPositive()
        ));
        $validated=array_values(array_filter($rows,static fn(array $r):bool=>($r['status']??'')==='VALIDATED'));
        $total=Decimal::fromString('0');
        foreach($rows as $row){
            $total=DecimalMath::add($total,Decimal::fromString((string)($row['expected_net_pnl']??'0')));
        }
        $count=count($rows);
        $positiveRate=$count===0
            ? Decimal::fromString('0')
            : DecimalMath::divide(Decimal::fromString((string)count($positive)),Decimal::fromString((string)$count),12);
        $validatedRate=$count===0
            ? Decimal::fromString('0')
            : DecimalMath::divide(Decimal::fromString((string)count($validated)),Decimal::fromString((string)$count),12);
        $average=$count===0
            ? Decimal::fromString('0')
            : DecimalMath::divide($total,Decimal::fromString((string)$count),12);

        return [
            'hypothesis'=>$code,
            'sample_count'=>$count,
            'skipped_count'=>$skipped,
            'positive_count'=>count($positive),
            'validated_count'=>count($validated),
            'positive_rate'=>$positiveRate->value(),
            'validated_rate'=>$validatedRate->value(),
            'expected_pnl_total'=>$total->value(),
            'expected_pnl_average'=>$average->value(),
            'execution_fidelity'=>'MEDIUM',
            'production_economics_reused'=>true,
            'rows'=>$rows,
        ];
    }

    private function replaySpotPerp(MarketSnapshot $snapshot,string $code,array $c):?array
    {
        $spot=$this->state($snapshot,$this->required($c,'spot_venue_id'),$this->required($c,'spot_instrument_id'));
        $perp=$this->state($snapshot,$this->required($c,'perp_venue_id'),$this->required($c,'perp_instrument_id'));
        if($spot===null||$perp===null)return null;

        $this->guard->assertAvailableAt($snapshot->createdAt,$spot->updatedAt,'spot state');
        $this->guard->assertAvailableAt($snapshot->createdAt,$perp->updatedAt,'perpetual state');
        $state=$this->stateFactory->build($spot,$perp,true);

        $fees=(array)$c['fees'];
        $slippage=(array)$c['slippage'];
        $quantity=$this->decimal($c,'quantity');
        $horizon=$this->positiveInt($c,'holding_horizon_seconds');
        $economics=$this->economics->historicalSpotPerp(
            $state->basis,$state->funding,$quantity,$horizon,
            Decimal::fromString((string)($fees['spot_rate']??'0')),
            Decimal::fromString((string)($fees['perp_rate']??'0')),
            Decimal::fromString((string)($slippage['round_trip_bps']??'0')),
            $this->decimal($c,'leverage'),
            Decimal::fromString((string)($c['target_basis_absolute']??'0')),
            Decimal::fromString((string)($c['risk_allowance']??'0')),
            Decimal::fromString((string)($c['network_costs']??'0')),
            Decimal::fromString((string)($c['emergency_hedge_buffer']??'0')),
            $code==='H4',
        );

        if($code==='H4'){
            $spotProfile=new SpotProfile(
                $spot->bestQuote?->askPrice->baseAsset??new AssetCode('UNKNOWN'),
                $spot->bestQuote?->askPrice->quoteAsset??new AssetCode('USD'),
                Decimal::fromString('0'),Decimal::fromString('0'),
                $spot->bestQuote?->askPrice->precision??8,$spot->bestQuote?->askQuantity->precision??8,
                (bool)($c['spot_margin_capability']??false),
                (bool)($c['spot_short_capability']??false),
                (bool)($c['spot_borrow_capability']??false),
            );
            $evaluation=$this->evaluator->basis(
                $state,$spotProfile,$economics,
                Decimal::fromString((string)($c['minimum_executable_basis']??'0'))
            );
        }else{
            $evaluation=$this->evaluator->fundingCapture($state,$economics);
        }

        $evidence=[
            'basis_bps'=>$state->basis->midBasisBps->value(),
            'funding_rate'=>$state->funding->rate->value(),
        ];
        $evidence['market_regime']=$this->regimes->classify($evidence,(array)($c['regime_thresholds']??[]))->value;
        return $this->row($snapshot,$evaluation,$economics->expectedNetPnl->value(),$evidence);
    }

    private function replayCrossVenue(MarketSnapshot $snapshot,array $c):?array
    {
        $a=$this->state($snapshot,$this->required($c,'venue_a_id'),$this->required($c,'instrument_a_id'));
        $b=$this->state($snapshot,$this->required($c,'venue_b_id'),$this->required($c,'instrument_b_id'));
        if($a===null||$b===null||$a->bestQuote===null||$b->bestQuote===null)return null;

        $this->guard->assertAvailableAt($snapshot->createdAt,$a->updatedAt,'venue A state');
        $this->guard->assertAvailableAt($snapshot->createdAt,$b->updatedAt,'venue B state');
        $fa=$this->funding($a);$fb=$this->funding($b);

        $fees=(array)$c['fees'];$slippage=(array)$c['slippage'];
        $quantity=$this->decimal($c,'quantity');$horizon=$this->positiveInt($c,'holding_horizon_seconds');
        $markA=$a->markPrice?->value??$a->bestQuote->midPrice();
        $markB=$b->markPrice?->value??$b->bestQuote->midPrice();

        $ab=$this->economics->crossVenueFunding(
            $fa,$fb,$markA,$markB,$quantity,$horizon,
            Decimal::fromString((string)($fees['venue_a_rate']??'0')),
            Decimal::fromString((string)($fees['venue_b_rate']??'0')),
            Decimal::fromString((string)($slippage['round_trip_bps']??'0')),
            $this->decimal($c,'venue_a_leverage'),$this->decimal($c,'venue_b_leverage'),
            Decimal::fromString((string)($c['risk_allowance']??'0')),
            Decimal::fromString((string)($c['network_costs']??'0')),
            Decimal::fromString((string)($c['emergency_hedge_buffer']??'0')),
            $snapshot->createdAt
        );
        $ba=$this->economics->crossVenueFunding(
            $fb,$fa,$markB,$markA,$quantity,$horizon,
            Decimal::fromString((string)($fees['venue_b_rate']??'0')),
            Decimal::fromString((string)($fees['venue_a_rate']??'0')),
            Decimal::fromString((string)($slippage['round_trip_bps']??'0')),
            $this->decimal($c,'venue_b_leverage'),$this->decimal($c,'venue_a_leverage'),
            Decimal::fromString((string)($c['risk_allowance']??'0')),
            Decimal::fromString((string)($c['network_costs']??'0')),
            Decimal::fromString((string)($c['emergency_hedge_buffer']??'0')),
            $snapshot->createdAt
        );

        $aLong=$ab->expectedNetPnl->compareTo($ba->expectedNetPnl)>=0;
        $economics=$aLong?$ab:$ba;
        $evaluation=$this->evaluator->crossVenueFunding($aLong?$fa:$fb,$aLong?$fb:$fa,$economics);
        $evidence=[
            'long_venue'=>$aLong?$a->venueId->value():$b->venueId->value(),
            'short_venue'=>$aLong?$b->venueId->value():$a->venueId->value(),
            'venue_a_rate'=>$fa->rate->value(),'venue_b_rate'=>$fb->rate->value(),
            'funding_rate'=>$aLong?$fb->rate->value():$fa->rate->value(),
        ];
        $evidence['market_regime']=$this->regimes->classify($evidence,(array)($c['regime_thresholds']??[]))->value;
        return $this->row($snapshot,$evaluation,$economics->expectedNetPnl->value(),$evidence);
    }

    private function state(MarketSnapshot $snapshot,string $venue,string $instrument):?MarketState
    {
        foreach($snapshot->instrumentStates as $state){
            if($state->venueId->value()===$venue&&$state->instrumentId->value()===$instrument)return $state;
        }
        return null;
    }

    private function funding(MarketState $state):FundingRateObservation
    {
        if($state->fundingRate===null||$state->fundingRate->eventType()!==MarketEventType::FundingRate){
            throw new DomainException('FUNDING_DATA_REQUIRED');
        }
        $a=$state->fundingRate->attributes;
        $interval=(int)($a['funding_interval_seconds']??0);
        if($interval<60)throw new DomainException('FUNDING_INTERVAL_REQUIRED');
        return new FundingRateObservation(
            $state->venueId,$state->instrumentId,$state->fundingRate->value,
            FundingRateType::tryFrom(strtoupper((string)($a['rate_type']??'VENUE_NATIVE')))??FundingRateType::VenueNative,
            $state->sourceTimestamp,$this->timestamp($a['next_settlement_at']??null),$interval,
            $this->optionalDecimal($a['cap']??null),$this->optionalDecimal($a['floor']??null),
            $state->sourceId->value(),$state->quality->score,
            FundingRateStatus::tryFrom(strtoupper((string)($a['status']??'UNKNOWN')))??FundingRateStatus::Unknown
        );
    }

    private function row(MarketSnapshot $snapshot,RelativeValueEvaluation $evaluation,string $pnl,array $evidence):array
    {
        return [
            'snapshot_id'=>$snapshot->snapshotId,
            'timestamp'=>$snapshot->createdAt->format(DATE_ATOM),
            'status'=>$evaluation->status->value,
            'executable'=>$evaluation->executable(),
            'expected_net_pnl'=>$pnl,
            'reasons'=>$evaluation->reasons,
            'evidence'=>array_replace($evaluation->evidence,$evidence),
        ];
    }

    private function required(array $c,string $key):string
    {
        $v=trim((string)($c[$key]??''));
        if($v==='')throw new InvalidArgumentException($key.' is required.');
        return $v;
    }
    private function decimal(array $c,string $key):Decimal{return Decimal::fromString($this->required($c,$key));}
    private function positiveInt(array $c,string $key):int
    {
        $v=(int)($c[$key]??0);if($v<1)throw new InvalidArgumentException($key.' must be positive.');return $v;
    }
    private function timestamp(mixed $value):?DateTimeImmutable
    {
        if($value===null||$value==='')return null;
        if((is_string($value)||is_int($value))&&ctype_digit((string)$value)){
            $raw=(string)$value;return (new DateTimeImmutable())->setTimestamp(strlen($raw)>10?intdiv((int)$raw,1000):(int)$raw);
        }
        return is_string($value)?new DateTimeImmutable($value):null;
    }
    private function optionalDecimal(mixed $v):?Decimal{return $v===null||$v===''?null:Decimal::fromString((string)$v);}
}
