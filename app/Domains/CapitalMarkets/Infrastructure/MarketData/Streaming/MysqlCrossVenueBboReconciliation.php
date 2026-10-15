<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\Service\CrossVenueBboConsensus;
use PDO;

/**
 * Bounded read-only projection from canonical LIVE persisted BBO. No direct
 * cross-venue token matching, fee arbitrage or automatic price promotion.
 */
final readonly class MysqlCrossVenueBboReconciliation
{
    public function __construct(private PDO $pdo,private CrossVenueBboConsensus $policy){}
    public function inspect(string $org,string $instrument,int $maxBps=30):array
    {
        if($org===''||strlen($org)>190||$instrument===''||strlen($instrument)>190
            ||$this->pdo->inTransaction())throw new DomainException('Invalid isolated venue comparison.');
        $q=$this->pdo->prepare(
            "SELECT s.source_id,s.venue_id,s.enabled AS source_enabled,s.priority AS source_priority,
                  h.clock_reliable,e.canonical_event_id,e.instrument_id,e.data_mode,
                  e.received_timestamp AS received_at,e.payload_json,q.trust_status,q.quality_score,
                  sub.status AS subscription_status
             FROM tn_capital_market_data_sources s
             JOIN tn_capital_market_subscriptions sub
                 ON sub.organization_id=s.organization_id AND sub.source_id=s.source_id
                AND sub.instrument_id=:instrument_sub AND sub.data_type='BBO'
                AND sub.status='ACTIVE'
             JOIN tn_capital_market_source_health h
                 ON h.organization_id=s.organization_id AND h.source_id=s.source_id
             JOIN tn_capital_market_canonical_events e
                 ON e.organization_id=s.organization_id AND e.source_id=s.source_id
                AND e.instrument_id=sub.instrument_id AND e.event_type='BBO'
                AND e.id=(SELECT latest.id FROM tn_capital_market_canonical_events latest
                   WHERE latest.organization_id=s.organization_id
                     AND latest.source_id=s.source_id
                     AND latest.instrument_id=sub.instrument_id
                     AND latest.event_type='BBO'
                   ORDER BY latest.received_timestamp DESC,latest.id DESC LIMIT 1)
             JOIN tn_capital_market_quality_metrics q
                ON q.organization_id=e.organization_id AND q.canonical_event_id=e.canonical_event_id
             WHERE s.organization_id=:org AND s.enabled=1
               AND s.license_profile<>'UNSPECIFIED'
               AND (JSON_SEARCH(s.roles_json,'one','TRADING_SOURCE') IS NOT NULL
                 OR JSON_SEARCH(s.roles_json,'one','VALIDATION_SOURCE') IS NOT NULL)
               AND EXISTS (
                   SELECT 1 FROM tn_capital_market_pool_external_evidence proof
                   WHERE proof.organization_id=s.organization_id
                     AND proof.subject_id=s.source_id
                     AND proof.kind='SOURCE_ENTITLEMENT'
                     AND proof.status='APPROVED'
                     AND proof.valid_until>UTC_TIMESTAMP(6)
                     AND proof.reviewed_by IS NOT NULL
                     AND proof.reviewed_by<>proof.submitted_by
               )
             ORDER BY s.priority,s.source_id LIMIT 101");
        $q->execute(['org'=>$org,'instrument_sub'=>$instrument]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>100)throw new DomainException('Too many independent venue sources.');
        $candidate=[];
        foreach($rows as $row){
            $payload=json_decode((string)$row['payload_json'],true,64,JSON_THROW_ON_ERROR);
            $bid=$payload['bid_price']??null;$ask=$payload['ask_price']??null;
            $candidate[]=[
                'source_id'=>$row['source_id'],'venue_id'=>$row['venue_id'],
                'instrument_id'=>$row['instrument_id'],'canonical_event_id'=>$row['canonical_event_id'],
                'data_mode'=>$row['data_mode'],'trust_status'=>$row['trust_status'],
                'quality_score'=>(int)$row['quality_score'],
                'source_enabled'=>(int)$row['source_enabled']===1,
                'clock_reliable'=>(int)$row['clock_reliable']===1,
                'subscription_active'=>$row['subscription_status']==='ACTIVE',
                'source_priority'=>(int)$row['source_priority'],
                'received_at'=>$row['received_at'],
                'bid_price'=>is_array($bid)?($bid['value']??null):null,
                'ask_price'=>is_array($ask)?($ask['value']??null):null,
                'quote_asset'=>is_array($bid)?($bid['quote_asset']??null):null,
            ];
        }
        return $this->policy->evaluate($instrument,$candidate,$maxBps);
    }

    /** Optional, append-only operator evidence; never changes subscriptions. */
    public function record(string $org,array $report):string
    {
        $instrument=$report['instrument_id']??null;
        $outcome=$report['status']??null;
        if($org===''||!is_string($instrument)||$instrument===''
            ||!in_array($outcome,['CONSENSUS','DIVERGED','NO_QUORUM','CURRENCY_MISMATCH'],true)){
            throw new DomainException('Malformed venue reconciliation evidence.');
        }
        $sources=array_values(array_unique(array_merge(
            array_column($report['evidence']??[],'source_id'),
            $report['trusted_sources']??[],
        )));
        sort($sources,SORT_STRING);
        $digest=hash('sha256',json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $id=bin2hex(random_bytes(16));
        $q=$this->pdo->prepare(
            'INSERT INTO tn_capital_market_venue_reconciliation
             (organization_id,review_id,instrument_id,outcome,evidence_digest,source_ids_json)
             VALUES (:org,:id,:instrument,:outcome,:digest,:sources)');
        $q->execute(['org'=>$org,'id'=>$id,'instrument'=>$instrument,'outcome'=>$outcome,
            'digest'=>$digest,'sources'=>json_encode($sources,JSON_THROW_ON_ERROR)]);
        return $id;
    }
}
