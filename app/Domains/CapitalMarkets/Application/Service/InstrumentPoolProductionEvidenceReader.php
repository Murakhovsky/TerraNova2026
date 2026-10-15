<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use PDO;

/**
 * Read-only, tenant-scoped 24-hour live evidence directly from canonical MySQL.
 * Crossed/one-sided/untrusted quality and unleased writers never pass.
 */
final readonly class InstrumentPoolProductionEvidenceReader
{
    public function __construct(
        private PDO $connection,
        private InstrumentPoolExternalEvidence $attestations,
        private InstrumentPoolProductionSoakPolicy $policy,
    ) {}

    /** @return array<string,mixed> */
    public function report(string $org,?string $soakStartedAt,array $allowedSources):array
    {
        if($org===''||strlen($org)>190||trim($org)!==$org)throw new DomainException('Tenant required.');
        if($allowedSources===[]||count($allowedSources)>16
            ||count(array_unique($allowedSources))!==count($allowedSources)){
            throw new DomainException('Explicit supervised source scope required.');
        }
        $scope=['org'=>$org];$placeholders=[];
        foreach($allowedSources as $i=>$source){
            if(!is_string($source)||$source===''||strlen($source)>190){
                throw new DomainException('Invalid supervised source.');
            }
            $scope['src'.$i]=$source;
            $placeholders[]=':src'.$i;
        }
        $start=$soakStartedAt===null?false:strtotime($soakStartedAt.' UTC');
        $elapsed=$start!==false&&$start<=time()-86400;
        $approvals=$this->attestations->approved($org);
        $sql=<<<'SQL'
SELECT e.source_id,e.instrument_id,
  CASE WHEN e.event_type='BBO' THEN 'BBO' ELSE 'L2' END AS kind,
  COUNT(*) AS samples,
  COUNT(DISTINCT FLOOR(TIMESTAMPDIFF(SECOND,e.received_timestamp,UTC_TIMESTAMP(6))/3600)) AS hour_buckets,
  SUM(CASE WHEN q.trust_status<>'TRUSTED' OR q.trust_status IS NULL
         OR q.quality_score<80 OR e.data_mode<>'LIVE'
         OR e.source_timestamp>DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 SECOND)
         OR e.received_timestamp>DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 SECOND)
    THEN 1 ELSE 0 END) AS bad_samples,
  GREATEST(0,TIMESTAMPDIFF(SECOND,MAX(e.received_timestamp),UTC_TIMESTAMP(6))) AS age_seconds,
  MIN(e.data_mode) AS min_mode,MAX(e.data_mode) AS max_mode
FROM tn_capital_market_canonical_events e
LEFT JOIN tn_capital_market_quality_metrics q
  ON q.organization_id=e.organization_id
 AND q.canonical_event_id=e.canonical_event_id
WHERE e.organization_id=:org
  AND e.source_id IN (__SOURCE_PLACEHOLDERS__)
  AND e.event_type IN ('BBO','ORDER_BOOK_SNAPSHOT')
  AND e.source_timestamp>=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 24 HOUR)
  AND e.received_timestamp>=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 24 HOUR)
  AND e.received_timestamp<=UTC_TIMESTAMP(6)
GROUP BY e.source_id,e.instrument_id,
  CASE WHEN e.event_type='BBO' THEN 'BBO' ELSE 'L2' END
LIMIT 3001
SQL;
        $query=$this->connection->prepare(str_replace('__SOURCE_PLACEHOLDERS__',
            implode(',',$placeholders),$sql));
        $query->execute($scope);
        $raw=$query->fetchAll(PDO::FETCH_ASSOC);
        if(count($raw)>3000)throw new DomainException('Soak evidence capacity exceeded.');
        $src=$this->connection->prepare(
            'SELECT source_id,enabled,license_profile,adapter_type
             FROM tn_capital_market_data_sources WHERE organization_id=:org');
        $src->execute(['org'=>$org]);
        $sources=[];
        foreach($src->fetchAll(PDO::FETCH_ASSOC) as $row)$sources[$row['source_id']]=$row;
        $lease=$this->connection->prepare(
            "SELECT DISTINCT source_id
             FROM tn_capital_market_stream_worker_leases
             WHERE organization_id=:org AND owner_id<>'' AND fencing_token>0
               AND expires_at>UTC_TIMESTAMP(6)");
        $lease->execute(['org'=>$org]);
        $leased=array_fill_keys($lease->fetchAll(PDO::FETCH_COLUMN),true);
        $instruments=$this->connection->prepare(
            'SELECT instrument_id,family,status,metadata_json
             FROM tn_capital_market_instruments WHERE organization_id=:org');
        $instruments->execute(['org'=>$org]);
        $indexed=[];
        foreach($instruments->fetchAll(PDO::FETCH_ASSOC) as $item)$indexed[$item['instrument_id']]=$item;
        $rows=[];
        foreach($raw as $r){
            $source=$sources[$r['source_id']]??null;
            $instrument=$indexed[$r['instrument_id']]??null;
            $metadata=is_array($instrument)?json_decode((string)$instrument['metadata_json'],true):null;
            $needsIssuer=is_array($instrument) && (
                in_array($instrument['family'],['tokenized_security','tokenized_fixed_income','rwa'],true)
                ||(is_array($metadata)&&($metadata['origin']??null)==='BINANCE_BSTOCKS_SEED')
            );
            $issuerOk=!$needsIssuer||isset($approvals['ISSUER_IDENTITY|'.$r['instrument_id']]);
            $isAllowedSource=is_array($source)&&$source['license_profile']!=='UNSPECIFIED'
                &&isset($approvals['SOURCE_ENTITLEMENT|'.$r['source_id']]);
            $rows[]=[
                'source_id'=>(string)$r['source_id'],
                'instrument_id'=>(string)$r['instrument_id'],
                'kind'=>(string)$r['kind'],
                'samples'=>(int)$r['samples'],
                'hour_buckets'=>(int)$r['hour_buckets'],
                'bad_samples'=>(int)$r['bad_samples'],
                'age_seconds'=>(int)$r['age_seconds'],
                'mode'=>$r['min_mode']===$r['max_mode']?$r['min_mode']:'MIXED',
                'approved_entitlement'=>$isAllowedSource,
                'issuer_verified'=>$issuerOk&&is_array($instrument)
                    &&$instrument['status']==='ACTIVE',
                'source_enabled'=>is_array($source)&&(int)$source['enabled']===1,
                'worker_active'=>isset($leased[$r['source_id']]),
            ];
        }
        $result=$this->policy->evaluate($rows,$elapsed);
        $observedSources=array_fill_keys(array_column($rows,'source_id'),true);
        $missingSources=array_values(array_filter($allowedSources,
            static fn(string $id):bool=>!isset($observedSources[$id])));
        $result['gates']['ALL_SUPERVISED_SOURCES_OBSERVED']=$missingSources===[];
        $result['accepted']=$result['accepted']&&$missingSources===[];
        $result['missing_sources']=$missingSources;
        $result['window_started_at']=$soakStartedAt;
        $result['checked_at_utc']=gmdate(DATE_ATOM);
        // This digest is for an ephemeral check, not a permanently valid warrant.
        $result['digest']=hash('sha256',json_encode([$org,$soakStartedAt,$rows],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $result;
    }

    public function canaryHealthy(string $org,array $allowedSourceIds):bool
    {
        if($allowedSourceIds===[]||count($allowedSourceIds)>16)return false;
        $approvals=$this->attestations->approved($org);
        $q=$this->connection->prepare(
            "SELECT e.source_id, COUNT(*) AS observations,
              SUM(CASE WHEN q.trust_status='TRUSTED'
                 AND q.quality_score>=80 AND e.data_mode='LIVE'
                 AND e.received_timestamp<=UTC_TIMESTAMP(6)
                 THEN 1 ELSE 0 END) AS trusted
             FROM tn_capital_market_canonical_events e
             LEFT JOIN tn_capital_market_quality_metrics q
               ON q.organization_id=e.organization_id AND q.canonical_event_id=e.canonical_event_id
             JOIN tn_capital_market_data_sources s
               ON s.organization_id=e.organization_id AND s.source_id=e.source_id AND s.enabled=1
             WHERE e.organization_id=:org
               AND e.received_timestamp>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE)
               AND e.event_type IN ('BBO','ORDER_BOOK_SNAPSHOT')
             GROUP BY e.source_id");
        $q->execute(['org'=>$org]);
        $seen=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if((int)$row['observations']>0
                &&(int)$row['observations']===(int)$row['trusted']){
                $seen[$row['source_id']]=true;
            }
        }
        $leases=$this->connection->prepare(
            "SELECT DISTINCT source_id FROM tn_capital_market_stream_worker_leases
             WHERE organization_id=:org AND owner_id<>'' AND fencing_token>0
               AND expires_at>UTC_TIMESTAMP(6)");
        $leases->execute(['org'=>$org]);
        $active=array_fill_keys($leases->fetchAll(PDO::FETCH_COLUMN),true);
        foreach($allowedSourceIds as $source){
            if(!is_string($source)||!isset($seen[$source])||!isset($active[$source])
                ||!isset($approvals['SOURCE_ENTITLEMENT|'.$source]))return false;
        }
        return true;
    }
}
