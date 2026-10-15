<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use PDO;

/** Tenant-scoped read-only production evidence check; never enables execution. */
final readonly class InstrumentPoolProductionReadiness
{
    public function __construct(
        private PDO $pdo,
        private InstrumentPoolSoakPolicy $policy,
        private InstrumentPoolExternalAttestations $external,
    ){}

    /** @return array<string,mixed> */
    public function report(string $org):array
    {
        if($org===''||trim($org)!==$org||strlen($org)>190)throw new DomainException('Invalid tenant.');
        $proof=$this->external->approvedDigests($org);
        $sources=[];
        $q=$this->pdo->prepare(
            'SELECT source_id,adapter_type,enabled,license_profile,credentials_reference,metadata_json
             FROM tn_capital_market_data_sources WHERE organization_id=:org'
        );
        $q->execute(['org'=>$org]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $id=$row['source_id'];
            $massive=$row['adapter_type']==='massive.stocks.v2';
            $kind=$massive?'MASSIVE_NBBO_ENTITLEMENT':'VENUE_ENTITLEMENT';
            $metadata=json_decode((string)$row['metadata_json'],true,64,JSON_THROW_ON_ERROR);
            $sources[$id]=is_array($metadata)&&(int)$row['enabled']===1
                &&isset($proof[$kind.'|'.$id])
                &&($metadata['data_mode']??null)==='LIVE'
                &&$row['license_profile']!=='UNSPECIFIED'
                &&(!$massive||($row['credentials_reference']!==null
                    &&$row['license_profile']==='MASSIVE_STOCKS_WS_Q_LIVE'));
        }
        $q=$this->pdo->prepare(
            "SELECT source_id FROM tn_capital_market_stream_worker_leases
             WHERE organization_id=:org AND expires_at>UTC_TIMESTAMP(6) AND owner_id<>''"
        );
        $q->execute(['org'=>$org]);
        $leased=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
        $q=$this->pdo->prepare(
            "SELECT source_id,instrument_id FROM tn_capital_market_subscriptions
             WHERE organization_id=:org AND status='ACTIVE' AND data_type='BBO'"
        );
        $q->execute(['org'=>$org]);
        $bbo=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$bbo[$row['source_id'].'|'.$row['instrument_id']]=true;

        // Actual canonical rows are joined to independent quality measurements.
        // Neither CLI arguments nor an uploaded fixture can supply sample counts.
        $q=$this->pdo->prepare(
            "SELECT e.source_id,e.instrument_id,
               CASE WHEN e.event_type='BBO' THEN 'BBO' ELSE 'L2' END AS kind,
               COUNT(*) AS samples,
               TIMESTAMPDIFF(SECOND,MIN(e.received_timestamp),MAX(e.received_timestamp)) AS span_seconds,
               TIMESTAMPDIFF(SECOND,MAX(e.received_timestamp),UTC_TIMESTAMP(6)) AS last_age_seconds,
               SUM(CASE WHEN e.data_mode<>'LIVE'
                    OR m.canonical_event_id IS NULL OR m.trust_status<>'TRUSTED'
                    OR m.quality_score<80 OR JSON_LENGTH(m.flags_json)>0
                    THEN 1 ELSE 0 END) AS bad_samples,
               MIN(e.data_mode) AS min_mode,MAX(e.data_mode) AS max_mode
             FROM tn_capital_market_canonical_events e
             LEFT JOIN tn_capital_market_quality_metrics m
               ON m.organization_id=e.organization_id AND m.canonical_event_id=e.canonical_event_id
             WHERE e.organization_id=:org
               AND e.received_timestamp>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)
               AND e.event_type IN ('BBO','ORDER_BOOK_SNAPSHOT','ORDER_BOOK_DELTA')
             GROUP BY e.source_id,e.instrument_id,kind LIMIT 5001"
        );
        $q->execute(['org'=>$org]);
        $observations=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(count($observations)>=5000)throw new DomainException('Soak telemetry exceeds bounded scope.');
            $key=$row['source_id'].'|'.$row['instrument_id'];
            if($row['kind']==='BBO'&&!isset($bbo[$key]))continue;
            $observations[]=[
                'kind'=>$row['kind'],'source_id'=>$row['source_id'],
                'instrument_id'=>$row['instrument_id'],
                'samples'=>(int)$row['samples'],'span_seconds'=>max(0,(int)$row['span_seconds']),
                'last_age_seconds'=>max(0,(int)$row['last_age_seconds']),
                'bad_samples'=>(int)$row['bad_samples'],
                'data_mode'=>$row['min_mode']==='LIVE'&&$row['max_mode']==='LIVE'?'LIVE':'UNVERIFIED',
                'source_admitted'=>$sources[$row['source_id']]??false,
                'live_worker'=>$leased[$row['source_id']]??false,
            ];
        }
        $soak=$this->policy->evaluate($observations);

        $q=$this->pdo->prepare(
            "SELECT instrument_id FROM tn_capital_market_instruments
             WHERE organization_id=:org AND instrument_id LIKE 'cm_bstock_%' AND status='ACTIVE'"
        );
        $q->execute(['org'=>$org]);
        $tokens=$q->fetchAll(PDO::FETCH_COLUMN);
        $q=$this->pdo->prepare(
            "SELECT source_instrument_id,target_instrument_id,metadata_json
             FROM tn_capital_market_relationships
             WHERE organization_id=:org AND status='ACTIVE'"
        );
        $q->execute(['org'=>$org]);
        $routes=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $link){
            $m=json_decode((string)$link['metadata_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($m))continue;
            foreach(['source_instrument_id','target_instrument_id'] as $field){
                $id=(string)$link[$field];
                if(isset($proof['ISSUER_IDENTITY|'.$id])
                    &&hash_equals($proof['ISSUER_IDENTITY|'.$id],(string)($m['issuer_evidence_sha256']??''))
                    &&($m['evidence_tier']??null)==='E4'
                    &&($m['underlying_verified']??false)===true
                    &&($m['unit_multiplier_verified']??false)===true
                    &&($m['corporate_action_version_verified']??false)===true
                    &&($m['route_verified']??false)===true
                    &&($m['fees_verified']??false)===true
                    &&($m['conversion_rights_verified']??false)===true)$routes[$id]=true;
            }
        }
        $confirmed=count(array_filter($tokens,static fn(string $id):bool=>isset($routes[$id])));
        $issuerOk=$tokens!==[]&&$confirmed===count($tokens);
        $gates=['ISSUER_IDENTITY_E4'=>$issuerOk,'LIVE_MYSQL_SOAK_250_BBO_50_L2'=>$soak['accepted']];
        return [
            'organization_id'=>$org,'internal_readiness'=>!in_array(false,$gates,true),
            'gates'=>$gates,'active_seed_tokens'=>count($tokens),
            'issuer_route_verified'=>$confirmed,
            'human_reviewed_documents'=>count($proof),
            'soak'=>$soak,
            'external_provider_entitlement_not_proven_by_document_review'=>true,
            'trading_enabled'=>false,
        ];
    }
}
