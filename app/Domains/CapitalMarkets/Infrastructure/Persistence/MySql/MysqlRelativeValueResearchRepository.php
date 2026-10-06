<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\RelativeValueResearchRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\BasisObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingSettlement;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeGroup;
use PDO;

final readonly class MysqlRelativeValueResearchRepository implements RelativeValueResearchRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function saveFundingObservation(string $organizationId,string $observationId,FundingRateObservation $o):void
    {
        $payload=$this->fundingPayload($o);
        $this->q(
            'INSERT INTO tn_capital_market_funding_observations
             (organization_id,observation_id,venue_id,instrument_id,rate,rate_type,status,observation_at,next_settlement_at,
              funding_interval_seconds,cap,floor,quality,source,payload_json)
             VALUES (:org,:id,:venue,:instrument,:rate,:rate_type,:status,:observed,:next_at,:interval,:cap,:floor,:quality,:source,:payload)
             ON DUPLICATE KEY UPDATE rate=VALUES(rate),status=VALUES(status),next_settlement_at=VALUES(next_settlement_at),
             funding_interval_seconds=VALUES(funding_interval_seconds),quality=VALUES(quality),payload_json=VALUES(payload_json)',
            [
                'org'=>$organizationId,'id'=>$observationId,'venue'=>$o->venue->value(),'instrument'=>$o->perpetualInstrument->value(),
                'rate'=>$o->rate->value(),'rate_type'=>$o->rateType->value,'status'=>$o->status->value,
                'observed'=>$this->date($o->observationTimestamp),'next_at'=>$o->nextSettlementAt? $this->date($o->nextSettlementAt):null,
                'interval'=>$o->fundingIntervalSeconds,'cap'=>$o->cap?->value(),'floor'=>$o->floor?->value(),
                'quality'=>$o->quality,'source'=>$o->source,'payload'=>$this->json($payload),
            ]
        );
    }

    public function listFundingObservations(string $organizationId,?string $venueId=null,?string $instrumentId=null,int $limit=5000):array
    {
        $limit=max(1,min(10000,$limit));
        $sql='SELECT payload_json FROM tn_capital_market_funding_observations WHERE organization_id=:org';
        $params=['org'=>$organizationId];
        if($venueId!==null){$sql.=' AND venue_id=:venue';$params['venue']=$venueId;}
        if($instrumentId!==null){$sql.=' AND instrument_id=:instrument';$params['instrument']=$instrumentId;}
        return $this->rows($sql.' ORDER BY observation_at ASC,id ASC LIMIT '.$limit,$params);
    }

    public function saveBasisObservation(string $organizationId,string $observationId,string $marketPairId,BasisObservation $o):void
    {
        $payload=[
            'market_pair_id'=>$marketPairId,'spot_market'=>$o->spotMarket,'perpetual_market'=>$o->perpetualMarket,
            'timestamp'=>$o->timestamp->format(DATE_ATOM),'spot_bid'=>$o->spotBid->value(),'spot_ask'=>$o->spotAsk->value(),
            'spot_mid'=>$o->spotMid->value(),'perp_bid'=>$o->perpBid->value(),'perp_ask'=>$o->perpAsk->value(),
            'perp_mid'=>$o->perpMid->value(),'mark_price'=>$o->markPrice?->value(),'index_price'=>$o->indexPrice?->value(),
            'mid_basis_absolute'=>$o->midBasisAbsolute->value(),'mid_basis_bps'=>$o->midBasisBps->value(),
            'long_spot_short_perp_executable_basis'=>$o->longSpotShortPerpExecutableBasis->value(),
            'short_spot_long_perp_executable_basis'=>$o->shortSpotLongPerpExecutableBasis->value(),'quality'=>$o->quality,
        ];
        $this->q(
            'INSERT INTO tn_capital_market_basis_observations
             (organization_id,observation_id,market_pair_id,spot_market,perpetual_market,observed_at,spot_bid,spot_ask,spot_mid,
              perp_bid,perp_ask,perp_mid,mark_price,index_price,mid_basis_absolute,mid_basis_bps,long_spot_short_perp_basis,
              short_spot_long_perp_basis,quality,payload_json)
             VALUES (:org,:id,:pair,:spot,:perp,:at,:spot_bid,:spot_ask,:spot_mid,:perp_bid,:perp_ask,:perp_mid,:mark,:idx,
              :basis_abs,:basis_bps,:premium,:discount,:quality,:payload)
             ON DUPLICATE KEY UPDATE mid_basis_absolute=VALUES(mid_basis_absolute),mid_basis_bps=VALUES(mid_basis_bps),
             long_spot_short_perp_basis=VALUES(long_spot_short_perp_basis),
             short_spot_long_perp_basis=VALUES(short_spot_long_perp_basis),quality=VALUES(quality),payload_json=VALUES(payload_json)',
            [
                'org'=>$organizationId,'id'=>$observationId,'pair'=>$marketPairId,'spot'=>$o->spotMarket,'perp'=>$o->perpetualMarket,
                'at'=>$this->date($o->timestamp),'spot_bid'=>$o->spotBid->value(),'spot_ask'=>$o->spotAsk->value(),
                'spot_mid'=>$o->spotMid->value(),'perp_bid'=>$o->perpBid->value(),'perp_ask'=>$o->perpAsk->value(),
                'perp_mid'=>$o->perpMid->value(),'mark'=>$o->markPrice?->value(),'idx'=>$o->indexPrice?->value(),
                'basis_abs'=>$o->midBasisAbsolute->value(),'basis_bps'=>$o->midBasisBps->value(),
                'premium'=>$o->longSpotShortPerpExecutableBasis->value(),'discount'=>$o->shortSpotLongPerpExecutableBasis->value(),
                'quality'=>$o->quality,'payload'=>$this->json($payload),
            ]
        );
    }

    public function listBasisObservations(string $organizationId,string $marketPairId,int $limit=5000):array
    {
        return $this->rows(
            'SELECT payload_json FROM tn_capital_market_basis_observations
             WHERE organization_id=:org AND market_pair_id=:pair ORDER BY observed_at ASC,id ASC LIMIT '.max(1,min(10000,$limit)),
            ['org'=>$organizationId,'pair'=>$marketPairId]
        );
    }

    public function saveFundingSettlement(string $organizationId,string $settlementId,FundingSettlement $s):void
    {
        $payload=[
            'venue_id'=>$s->venue->value(),'instrument_id'=>$s->instrument->value(),'position_reference'=>$s->positionReference,
            'settlement_at'=>$s->settlementAt->format(DATE_ATOM),'rate'=>$s->rate->value(),
            'position_notional'=>$s->positionNotional->value(),'side'=>$s->side->value,'gross_cashflow'=>$s->grossCashflow->value(),
            'currency'=>$s->currency->value(),'source'=>$s->source,
        ];
        $this->q(
            'INSERT IGNORE INTO tn_capital_market_funding_settlements
             (organization_id,settlement_id,venue_id,instrument_id,position_reference,settlement_at,rate,position_notional,
              side,gross_cashflow,currency,source,payload_json)
             VALUES (:org,:id,:venue,:instrument,:position,:at,:rate,:notional,:side,:cashflow,:currency,:source,:payload)',
            [
                'org'=>$organizationId,'id'=>$settlementId,'venue'=>$s->venue->value(),'instrument'=>$s->instrument->value(),
                'position'=>$s->positionReference,'at'=>$this->date($s->settlementAt),'rate'=>$s->rate->value(),
                'notional'=>$s->positionNotional->value(),'side'=>$s->side->value,'cashflow'=>$s->grossCashflow->value(),
                'currency'=>$s->currency->value(),'source'=>$s->source,'payload'=>$this->json($payload),
            ]
        );
    }

    public function listFundingSettlements(string $organizationId,?string $positionReference=null,int $limit=5000):array
    {
        $sql='SELECT payload_json FROM tn_capital_market_funding_settlements WHERE organization_id=:org';
        $params=['org'=>$organizationId];
        if($positionReference!==null){$sql.=' AND position_reference=:position';$params['position']=$positionReference;}
        return $this->rows($sql.' ORDER BY settlement_at ASC,id ASC LIMIT '.max(1,min(10000,$limit)),$params);
    }

    public function saveHedgeGroup(string $organizationId,HedgeGroup $g,?string $opportunityId=null,?string $executionId=null):void
    {
        $actual=$g->netUnderlyingExposure();
        $legs=array_map(static fn($leg):array=>[
            'instrument_id'=>$leg->instrumentId,'venue_id'=>$leg->venueId,'side'=>$leg->side->value,
            'quantity'=>$leg->quantity->value(),'underlying_per_unit'=>$leg->underlyingPerUnit->value(),
            'contract_multiplier'=>$leg->contractMultiplier->value(),'underlying_exposure'=>$leg->underlyingExposure()->value(),
        ],$g->legs);
        $payload=[
            'hedge_group_id'=>$g->id,'strategy_version'=>$g->strategyVersion,'state'=>$g->state->value,
            'target_delta'=>$g->targetDelta->value(),'actual_delta'=>$actual->value(),
            'allowed_tolerance'=>$g->allowedTolerance->value(),'opportunity_id'=>$opportunityId,'execution_id'=>$executionId,'legs'=>$legs,
        ];
        $this->q(
            'INSERT INTO tn_capital_market_hedge_groups
             (organization_id,hedge_group_id,strategy_version,opportunity_id,execution_id,state,target_delta,actual_delta,allowed_tolerance,payload_json)
             VALUES (:org,:id,:strategy,:opportunity,:execution,:state,:target,:actual,:tolerance,:payload)
             ON DUPLICATE KEY UPDATE execution_id=VALUES(execution_id),state=VALUES(state),actual_delta=VALUES(actual_delta),payload_json=VALUES(payload_json)',
            [
                'org'=>$organizationId,'id'=>$g->id,'strategy'=>$g->strategyVersion,'opportunity'=>$opportunityId,'execution'=>$executionId,
                'state'=>$g->state->value,'target'=>$g->targetDelta->value(),'actual'=>$actual->value(),
                'tolerance'=>$g->allowedTolerance->value(),'payload'=>$this->json($payload),
            ]
        );
    }

    public function getHedgeGroup(string $organizationId,string $hedgeGroupId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_hedge_groups WHERE organization_id=:org AND hedge_group_id=:id LIMIT 1'
        );
        $statement->execute(['org'=>$organizationId,'id'=>$hedgeGroupId]);
        $json=$statement->fetchColumn();
        return is_string($json)?$this->decode($json):null;
    }

    private function fundingPayload(FundingRateObservation $o):array
    {
        return [
            'venue_id'=>$o->venue->value(),'instrument_id'=>$o->perpetualInstrument->value(),'rate'=>$o->rate->value(),
            'rate_type'=>$o->rateType->value,'observation_timestamp'=>$o->observationTimestamp->format(DATE_ATOM),
            'next_settlement_at'=>$o->nextSettlementAt?->format(DATE_ATOM),'funding_interval_seconds'=>$o->fundingIntervalSeconds,
            'cap'=>$o->cap?->value(),'floor'=>$o->floor?->value(),'source'=>$o->source,'quality'=>$o->quality,'status'=>$o->status->value,
        ];
    }

    private function q(string $sql,array $params):void{$this->connection->prepare($sql)->execute($params);}
    private function rows(string $sql,array $params):array
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
        return array_map(fn(array $row):array=>$this->decode((string)$row['payload_json']),$statement->fetchAll(PDO::FETCH_ASSOC));
    }
    private function json(array $payload):string{return json_encode($payload,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);}
    private function decode(string $json):array{$v=json_decode($json,true,flags:JSON_THROW_ON_ERROR);return is_array($v)&&!array_is_list($v)?$v:[];}
    private function date(DateTimeImmutable $date):string{return $date->format('Y-m-d H:i:s.u');}
}
