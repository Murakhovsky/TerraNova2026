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
        if($allowedSources===[]||count($allowedSources)>64
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
        $digests=$this->attestations->approvedDigests($org);
        $approvals=array_fill_keys(array_keys($digests),true);
        $issuerRoutes=$this->verifiedIssuerRoutes($org,$digests);
        $sql=<<<'SQL'
SELECT e.source_id,e.instrument_id,
  CASE WHEN e.event_type='BBO' THEN 'BBO' ELSE 'L2' END AS kind,
  COUNT(*) AS samples,
  COUNT(DISTINCT FLOOR(TIMESTAMPDIFF(SECOND,e.received_timestamp,UTC_TIMESTAMP(6))/3600)) AS hour_buckets,
  COUNT(DISTINCT FLOOR(TIMESTAMPDIFF(SECOND,e.received_timestamp,UTC_TIMESTAMP(6))/300)) AS five_minute_buckets,
  SUM(CASE WHEN q.trust_status<>'TRUSTED' OR q.trust_status IS NULL
         OR q.quality_score<80 OR e.data_mode<>'LIVE'
         OR q.event_age_ms>120000 OR q.ingestion_latency_ms>10000
         OR q.processing_latency_ms>10000
         OR ABS(TIMESTAMPDIFF(SECOND,e.source_timestamp,e.received_timestamp))>120
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
            'SELECT source_id,venue_id,enabled,license_profile,adapter_type,
                    credentials_reference,metadata_json
             FROM tn_capital_market_data_sources WHERE organization_id=:org');
        $src->execute(['org'=>$org]);
        $sources=[];
        foreach($src->fetchAll(PDO::FETCH_ASSOC) as $row)$sources[$row['source_id']]=$row;
        $health=$this->connection->prepare(
            "SELECT source_id,connection_state,clock_reliable,queue_lag,rate_limit_state,
                    last_event_at,updated_at
             FROM tn_capital_market_source_health WHERE organization_id=:org");
        $health->execute(['org'=>$org]);
        $now=time();$healthy=[];
        foreach($health->fetchAll(PDO::FETCH_ASSOC) as $h){
            $eventTime=$h['last_event_at']===null?false:strtotime($h['last_event_at'].' UTC');
            $updateTime=strtotime($h['updated_at'].' UTC');
            $healthy[$h['source_id']]=$h['connection_state']==='ACTIVE'
                &&(int)$h['clock_reliable']===1
                &&(int)$h['queue_lag']<=100
                &&!in_array($h['rate_limit_state'],['THROTTLED','EXHAUSTED'],true)
                &&$eventTime!==false&&$updateTime!==false
                &&$eventTime<=$now+5&&$eventTime>=$now-120
                &&$updateTime<=$now+5&&$updateTime>=$now-120;
        }
        $sub=$this->connection->prepare(
            "SELECT source_id,instrument_id,data_type
             FROM tn_capital_market_subscriptions
             WHERE organization_id=:org AND status='ACTIVE'
               AND data_type IN ('BBO','ORDER_BOOK_SNAPSHOT','ORDER_BOOK_DELTA')");
        $sub->execute(['org'=>$org]);
        $subscribed=[];
        foreach($sub->fetchAll(PDO::FETCH_ASSOC) as $entry){
            $subscribed[$entry['source_id'].'|'.$entry['instrument_id'].'|'.$entry['data_type']]=true;
        }
        $gaps=$this->connection->prepare(
            "SELECT source_id,instrument_id,data_type
             FROM tn_capital_market_data_gaps
             WHERE organization_id=:org AND status NOT IN ('RESOLVED','CLOSED')
               AND gap_from<=UTC_TIMESTAMP(6)
               AND gap_to>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)
               AND data_type IN ('BBO','ORDER_BOOK_SNAPSHOT','ORDER_BOOK_DELTA')");
        $gaps->execute(['org'=>$org]);
        $unresolved=[];
        foreach($gaps->fetchAll(PDO::FETCH_ASSOC) as $gap){
            $unresolved[$gap['source_id'].'|'.$gap['instrument_id'].'|'.$gap['data_type']]=true;
        }
        $assignments=$this->connection->prepare(
            'SELECT source_id,instrument_id,kind,shard_key
             FROM tn_capital_market_pool_stream_assignments WHERE organization_id=:org');
        $assignments->execute(['org'=>$org]);
        $assignmentMap=[];
        foreach($assignments->fetchAll(PDO::FETCH_ASSOC) as $a){
            $assignmentMap[$a['source_id'].'|'.$a['instrument_id'].'|'.$a['kind']]=$a['shard_key'];
        }
        $lease=$this->connection->prepare(
            "SELECT source_id,shard_key
             FROM tn_capital_market_stream_worker_leases
             WHERE organization_id=:org AND owner_id<>'' AND fencing_token>0
               AND expires_at>UTC_TIMESTAMP(6)");
        $lease->execute(['org'=>$org]);
        $leased=[];
        foreach($lease->fetchAll(PDO::FETCH_ASSOC) as $l){
            $leased[$l['source_id'].'|'.$l['shard_key']]=true;
        }
        $instruments=$this->connection->prepare(
            'SELECT instrument_id,family,status,metadata_json
             FROM tn_capital_market_instruments WHERE organization_id=:org');
        $instruments->execute(['org'=>$org]);
        $indexed=[];
        foreach($instruments->fetchAll(PDO::FETCH_ASSOC) as $item)$indexed[$item['instrument_id']]=$item;
        // Symbol-level ledger is the final, independent acceptance authority.
        // Healthy transport/source is insufficient if one instrument is stale.
        $streamRows=$this->connection->prepare(
            'SELECT source_id,instrument_id,channel,status,recovery_required,
                    last_event_at,last_rejected_at,last_quality_score
             FROM tn_capital_market_instrument_stream_trust
             WHERE organization_id=:org'
        );
        $streamRows->execute(['org'=>$org]);
        $symbolTrust=[];
        foreach($streamRows->fetchAll(PDO::FETCH_ASSOC) as $t){
            $symbolTrust[$t['source_id'].'|'.$t['instrument_id'].'|'.$t['channel']]=$t;
        }
        // Only actual protocol ACK + accepted canonical LIVE quotes qualify.
        // Both current session and valid lease token are checked in receipts.
        $receiptRows=$this->connection->prepare(
            "SELECT p.source_id,p.instrument_id,p.channel,p.shard_key,p.status,p.session_epoch,
                    p.ack_at,p.last_live_quote_at,p.last_quality_score,p.quote_count,
                    CASE WHEN l.owner_id<>'' AND l.fencing_token=p.fencing_token
                          AND l.expires_at>UTC_TIMESTAMP(6)
                         THEN 1 ELSE 0 END AS current_lease
             FROM tn_capital_market_provider_stream_receipts p
             LEFT JOIN tn_capital_market_stream_worker_leases l
               ON l.organization_id=p.organization_id
              AND l.source_id=p.source_id AND l.shard_key=p.shard_key
             WHERE p.organization_id=:org");
        $receiptRows->execute(['org'=>$org]);
        $verifiedReceipts=[];
        foreach($receiptRows->fetchAll(PDO::FETCH_ASSOC) as $item){
            $key=$item['source_id'].'|'.$item['instrument_id'].'|'.$item['channel'];
            $verifiedReceipts[$key]=$item;
        }
        $rows=[];
        foreach($raw as $r){
            $source=$sources[$r['source_id']]??null;
            $instrument=$indexed[$r['instrument_id']]??null;
            $metadata=is_array($instrument)?json_decode((string)$instrument['metadata_json'],true):null;
            $needsIssuer=is_array($instrument) && (
                in_array($instrument['family'],['tokenized_security','tokenized_fixed_income','rwa'],true)
                ||(is_array($metadata)&&($metadata['origin']??null)==='BINANCE_BSTOCKS_SEED')
            );
            $issuerOk=!$needsIssuer||(isset($digests['ISSUER_IDENTITY|'.$r['instrument_id']])
                &&isset($issuerRoutes[$r['instrument_id']]));
            $isAllowedSource=is_array($source)&&$this->sourceApproved($source,$approvals);
            $subKey=$r['source_id'].'|'.$r['instrument_id'].'|';
            $types=$r['kind']==='BBO'?['BBO']:['ORDER_BOOK_SNAPSHOT','ORDER_BOOK_DELTA'];
            $activeSubscription=false;$hasGap=false;
            foreach($types as $type){
                if(isset($subscribed[$subKey.$type]))$activeSubscription=true;
                if(isset($unresolved[$subKey.$type]))$hasGap=true;
            }
            $assignmentKey=$r['source_id'].'|'.$r['instrument_id'].'|'.$r['kind'];
            $shard=$assignmentMap[$assignmentKey]??null;
            $t=$symbolTrust[$assignmentKey]??null;
            $symbolGood=is_array($t)
                &&$t['status']==='TRUSTED'
                &&(int)$t['recovery_required']===0
                &&(int)$t['last_quality_score']>=80
                &&is_string($t['last_event_at'])
                &&($last=strtotime($t['last_event_at'].' UTC'))!==false
                &&$last>=time()-300&&$last<=time()+5
                &&($t['last_rejected_at']===null
                    ||(($badAt=strtotime($t['last_rejected_at'].' UTC'))!==false
                        &&$badAt<time()-86400));
            $receipt=$verifiedReceipts[$assignmentKey]??null;
            $quoteTime=is_array($receipt)&&is_string($receipt['last_live_quote_at'])
                ?strtotime($receipt['last_live_quote_at'].' UTC'):false;
            $ackTime=is_array($receipt)&&is_string($receipt['ack_at'])
                ?strtotime($receipt['ack_at'].' UTC'):false;
            // L2 transport has its own authenticated snapshot/recovery
            // protocol; BBO receipt rows must originate from the two approved
            // multi-symbol WS protocols. Do not forge L2 provider ACKs.
            $providerVerified=is_array($receipt)
                &&$shard!==null&&$receipt['shard_key']===$shard
                &&(int)$receipt['current_lease']===1
                &&$receipt['status']==='LIVE_QUOTE'
                &&(int)$receipt['session_epoch']>0
                &&(int)$receipt['quote_count']>0
                &&(int)$receipt['last_quality_score']>=80
                &&$quoteTime!==false&&$quoteTime>=time()-300&&$quoteTime<=time()+5
                &&$ackTime!==false&&$ackTime<=time()+5;
            $rows[]=[
                'stream_trust_confirmed'=>$symbolGood,
                'provider_live_verified'=>$providerVerified,
                'assignment_registered'=>$shard!==null,
                'source_healthy'=>$healthy[$r['source_id']]??false,
                'subscription_active'=>$activeSubscription,
                'unresolved_gap'=>$hasGap,
                'source_id'=>(string)$r['source_id'],
                'instrument_id'=>(string)$r['instrument_id'],
                'kind'=>(string)$r['kind'],
                'samples'=>(int)$r['samples'],
                'hour_buckets'=>(int)$r['hour_buckets'],
                'five_minute_buckets'=>(int)$r['five_minute_buckets'],
                'bad_samples'=>(int)$r['bad_samples'],
                'age_seconds'=>(int)$r['age_seconds'],
                'mode'=>$r['min_mode']===$r['max_mode']?$r['min_mode']:'MIXED',
                'approved_entitlement'=>$isAllowedSource,
                'issuer_verified'=>$issuerOk&&is_array($instrument)
                    &&$instrument['status']==='ACTIVE',
                'source_enabled'=>is_array($source)&&(int)$source['enabled']===1,
                'worker_active'=>$shard!==null&&isset($leased[$r['source_id'].'|'.$shard]),
            ];
        }
        $result=$this->policy->evaluate($rows,$elapsed);
        $observedSources=array_fill_keys(array_column($rows,'source_id'),true);
        $missingSources=array_values(array_filter($allowedSources,
            static fn(string $id):bool=>!isset($observedSources[$id])));
        $result['gates']['ALL_SUPERVISED_SOURCES_OBSERVED']=$missingSources===[];
        $result['accepted']=$result['accepted']&&$missingSources===[];
        $result['missing_sources']=$missingSources;
        // Each included source must prove at least one supervised fencing
        // challenge and a new, ACK-confirmed and quality-trusted owner in this
        // SOAK epoch. A CI fixture or mere process restart is insufficient.
        $failover=[];
        foreach($allowedSources as $candidate){
            $check=$this->connection->prepare(
                "SELECT 1 FROM tn_capital_market_stream_failover_observations i
                 JOIN tn_capital_market_stream_failover_observations r
                   ON r.organization_id=i.organization_id AND r.source_id=i.source_id
                  AND r.observation='RECOVERED' AND r.healthy=1 AND r.id>i.id
                  AND r.previous_token=i.previous_token
                  AND r.current_token>i.previous_token
                 WHERE i.organization_id=:org AND i.source_id=:source
                   AND i.observation='INJECTED'
                   AND i.observed_at>=:started LIMIT 1");
            $check->execute(['org'=>$org,'source'=>$candidate,
                'started'=>$soakStartedAt??'1970-01-01 00:00:00']);
            $failover[$candidate]=$check->fetchColumn()!==false;
        }
        $result['gates']['SOURCE_FAILOVER_PROVEN']=!in_array(false,$failover,true);
        $result['accepted']=$result['accepted']&&$result['gates']['SOURCE_FAILOVER_PROVEN'];
        $result['failover_by_source']=$failover;
        $result['window_started_at']=$soakStartedAt;
        $result['checked_at_utc']=gmdate(DATE_ATOM);
        // This digest is for an ephemeral check, not a permanently valid warrant.
        $result['digest']=hash('sha256',json_encode([$org,$soakStartedAt,$rows],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $result;
    }

    /** @param array<string,mixed> $source @param array<string,bool> $approvals */
    private function sourceApproved(array $source,array $approvals):bool
    {
        $id=(string)($source['source_id']??'');
        $venue=(string)($source['venue_id']??'');
        $metadata=json_decode((string)($source['metadata_json']??''),true);
        if($id===''||(int)($source['enabled']??0)!==1
            ||($source['license_profile']??null)==='UNSPECIFIED'
            ||!isset($approvals['SOURCE_ENTITLEMENT|'.$id])
            ||!is_array($metadata)||($metadata['data_mode']??null)!=='LIVE')return false;
        if($venue!==''&&!isset($approvals['VENUE_ENTITLEMENT|'.$venue]))return false;
        if(($source['adapter_type']??null)==='massive.stocks.v2'
            &&(!isset($approvals['MASSIVE_NBBO_ENTITLEMENT|'.$id])
                ||($source['credentials_reference']??null)===null
                ||$source['license_profile']!=='MASSIVE_STOCKS_WS_Q_LIVE'))return false;
        return true;
    }

    /** @param array<string,string> $digests @return array<string,bool> */
    private function verifiedIssuerRoutes(string $org,array $digests):array
    {
        $q=$this->connection->prepare(
            "SELECT r.source_instrument_id,r.target_instrument_id,r.metadata_json
             FROM tn_capital_market_relationships r
             JOIN tn_capital_market_instruments a
               ON a.organization_id=r.organization_id
              AND a.instrument_id=r.source_instrument_id AND a.status='ACTIVE'
             JOIN tn_capital_market_instruments b
               ON b.organization_id=r.organization_id
              AND b.instrument_id=r.target_instrument_id AND b.status='ACTIVE'
             WHERE r.organization_id=:org AND r.status='ACTIVE'
               AND r.effective_from<=UTC_TIMESTAMP(6)
               AND (r.effective_to IS NULL OR r.effective_to>UTC_TIMESTAMP(6))
             LIMIT 5001");
        $q->execute(['org'=>$org]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>5000)throw new DomainException('Issuer relationships exceed bounded review scope.');
        $routes=[];
        foreach($rows as $row){
            $meta=json_decode((string)$row['metadata_json'],true);
            if(!is_array($meta))continue;
            foreach(['source_instrument_id','target_instrument_id'] as $field){
                $id=(string)$row[$field];
                $digest=$digests['ISSUER_IDENTITY|'.$id]??null;
                if($digest!==null && InstrumentPoolIssuerE4Policy::valid($meta,$digest)){
                    $routes[$id]=true;
                }
            }
        }
        return $routes;
    }

    /**
     * Record one authoritative per-5-minute checkpoint from the running watchdog.
     * A failing observation poisons the bucket even if a later retry succeeds.
     * Fixtures may exercise the SQL in CI but are never production receipts.
     */
    public function recordSoakCheckpoint(string $org,string $soakStart,array $sources):array
    {
        if($soakStart===''||$sources===[]||count($sources)>64
            ||count(array_unique($sources))!==count($sources)){
            throw new DomainException('Explicit active soak epoch and source scope required.');
        }
        // MySQL DATETIME(6) is returned with fractional seconds by PDO.
        $epoch=str_contains($soakStart,'.')?$soakStart:$soakStart.'.000000';
        $valid=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$epoch,
            new \DateTimeZone('UTC'));
        if($valid===false||$valid->format('Y-m-d H:i:s.u')!==$epoch){
            throw new DomainException('Invalid soak epoch.');
        }
        $healthy=$this->canaryHealthy($org,$sources);
        $digest=hash('sha256',json_encode([$org,$soakStart,$sources,$healthy],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $save=$this->connection->prepare(
            "INSERT INTO tn_capital_market_pool_soak_checkpoints
             (organization_id,soak_started_at,bucket_at,healthy,evidence_sha256)
             VALUES (:org,:epoch,
                DATE_SUB(DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%d %H:%i:00'),
                 INTERVAL (MINUTE(UTC_TIMESTAMP(6)) MOD 5) MINUTE),
                :healthy,:digest)
             ON DUPLICATE KEY UPDATE
                healthy=LEAST(healthy,VALUES(healthy)),
                evidence_sha256=IF(VALUES(healthy)=0,VALUES(evidence_sha256),evidence_sha256)");
        $save->execute(['org'=>$org,'epoch'=>$soakStart,'healthy'=>$healthy?1:0,
            'digest'=>$digest]);
        return ['healthy'=>$healthy,'checkpoint'=>$this->soakCheckpoints($org,$soakStart)];
    }

    /** @return array{observations:int,healthy:int,required:int,current:bool,accepted:bool} */
    public function soakCheckpoints(string $org,string $soakStart):array
    {
        $query=$this->connection->prepare(
            "SELECT COUNT(*) AS observations,COALESCE(SUM(healthy),0) AS healthy,
             MAX(bucket_at) AS last_bucket
             FROM tn_capital_market_pool_soak_checkpoints
             WHERE organization_id=:org AND soak_started_at=:epoch
               AND bucket_at>=:start_after
               AND bucket_at<DATE_ADD(:start_before,INTERVAL 24 HOUR)");
        $query->execute(['org'=>$org,'epoch'=>$soakStart,
            'start_after'=>$soakStart,'start_before'=>$soakStart]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        $observations=(int)($row['observations']??0);
        $healthy=(int)($row['healthy']??0);
        $last=$row['last_bucket']??null;
        $timestamp=is_string($last)?strtotime($last.' UTC'):false;
        $current=$timestamp!==false&&$timestamp>=time()-600;
        return ['observations'=>$observations,'healthy'=>$healthy,'required'=>276,
            'current'=>$current,
            'accepted'=>$observations>=276&&$healthy>=276&&$current];
    }

    public function canaryHealthy(string $org,array $allowedSourceIds):bool
    {
        if($allowedSourceIds===[]||count($allowedSourceIds)>64)return false;
        $approvals=$this->attestations->approved($org);
        $sourceQ=$this->connection->prepare(
            'SELECT source_id,venue_id,enabled,license_profile,adapter_type,
                    credentials_reference,metadata_json
             FROM tn_capital_market_data_sources WHERE organization_id=:org');
        $sourceQ->execute(['org'=>$org]);$admitted=[];
        foreach($sourceQ->fetchAll(PDO::FETCH_ASSOC) as $source){
            if($this->sourceApproved($source,$approvals))$admitted[$source['source_id']]=true;
        }
        $q=$this->connection->prepare(
            "SELECT e.source_id, COUNT(*) AS observations,
              SUM(CASE WHEN q.trust_status='TRUSTED'
                 AND q.quality_score>=80 AND e.data_mode='LIVE'
                 AND q.event_age_ms<=120000
                 AND q.ingestion_latency_ms<=10000
                 AND e.received_timestamp<=UTC_TIMESTAMP(6)
                 THEN 1 ELSE 0 END) AS trusted
             FROM tn_capital_market_canonical_events e
             LEFT JOIN tn_capital_market_quality_metrics q
               ON q.organization_id=e.organization_id AND q.canonical_event_id=e.canonical_event_id
             JOIN tn_capital_market_data_sources s
               ON s.organization_id=e.organization_id AND s.source_id=e.source_id AND s.enabled=1
             JOIN tn_capital_market_pool_stream_assignments a
               ON a.organization_id=e.organization_id AND a.source_id=e.source_id
              AND a.instrument_id=e.instrument_id
              AND a.kind=CASE WHEN e.event_type='BBO' THEN 'BBO' ELSE 'L2' END
             JOIN tn_capital_market_stream_worker_leases lease
               ON lease.organization_id=e.organization_id AND lease.source_id=e.source_id
              AND lease.shard_key=a.shard_key AND lease.owner_id<>'' AND lease.fencing_token>0
              AND lease.expires_at>UTC_TIMESTAMP(6)
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
        $health=$this->connection->prepare(
            "SELECT source_id FROM tn_capital_market_source_health
             WHERE organization_id=:org AND connection_state='ACTIVE'
               AND clock_reliable=1 AND queue_lag<=100
               AND rate_limit_state NOT IN ('THROTTLED','EXHAUSTED')
               AND updated_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 120 SECOND)
               AND last_event_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 120 SECOND)");
        $health->execute(['org'=>$org]);
        $healthy=array_fill_keys($health->fetchAll(PDO::FETCH_COLUMN),true);
        // Canary requires a real protocol ACK and a quality-accepted LIVE
        // quote from an unexpired, currently leased provider session.
        $receiptQ=$this->connection->prepare(
            "SELECT DISTINCT p.source_id FROM tn_capital_market_provider_stream_receipts p
             JOIN tn_capital_market_stream_worker_leases l
               ON l.organization_id=p.organization_id
              AND l.source_id=p.source_id AND l.shard_key=p.shard_key
              AND l.fencing_token=p.fencing_token AND l.owner_id<>''
              AND l.expires_at>UTC_TIMESTAMP(6)
             WHERE p.organization_id=:org AND p.status='LIVE_QUOTE'
               AND p.session_epoch>0 AND p.quote_count>0 AND p.last_quality_score>=80
               AND p.last_live_quote_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE)");
        $receiptQ->execute(['org'=>$org]);
        $ackSources=array_fill_keys($receiptQ->fetchAll(PDO::FETCH_COLUMN),true);
        $symbolQ=$this->connection->prepare(
            "SELECT DISTINCT source_id FROM tn_capital_market_instrument_stream_trust
             WHERE organization_id=:org AND status='TRUSTED' AND recovery_required=0
               AND last_quality_score>=80
               AND last_event_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE)");
        $symbolQ->execute(['org'=>$org]);
        $symbolSources=array_fill_keys($symbolQ->fetchAll(PDO::FETCH_COLUMN),true);
        foreach($allowedSourceIds as $source){
            if(!isset($symbolSources[$source])||!isset($ackSources[$source]))return false;
            if(!is_string($source)||!isset($seen[$source])||!isset($active[$source])||!isset($healthy[$source])
                ||!isset($admitted[$source]))return false;
        }
        return true;
    }
}
