<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\{
    MysqlRawMarketProvenance,MysqlCrossVenueBboReconciliation
};
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlRawMarketEventRepository;
use Domains\CapitalMarkets\Application\Service\CrossVenueBboConsensus;
use Domains\CapitalMarkets\Domain\MarketData\{RawMarketEvent,MarketSourceId,MarketDataMode};

if(getenv('COS_CM_REPLAY_AUDIT_TEST')!=='1'){
    throw new RuntimeException('Explicit replay audit MySQL flag required.');
}
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
$parts=parse_url((string)getenv('DATABASE_URL'));
if(!is_array($parts)||($parts['scheme']??null)!=='mysql')throw new RuntimeException('MySQL required.');
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $parts['host']??'mysql',(int)($parts['port']??3306),
    ltrim((string)($parts['path']??''),'/'));
$pdo=new PDO($dsn,rawurldecode((string)($parts['user']??'')),
    rawurldecode((string)($parts['pass']??'')),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$org='ci-provenance-'.bin2hex(random_bytes(6));$src='source:okx';
try{
    $pdo->prepare("INSERT INTO tn_capital_market_data_sources
        (organization_id,source_id,venue_id,adapter_type,enabled,priority,
         roles_json,rate_limit_policy_json,reconnect_policy_json,health_policy_json,
         license_profile,metadata_json)
         VALUES (:org,:src,NULL,'okx.spot.v5',1,50,
             '[\"VALIDATION_SOURCE\"]','{}','{}','{}','PUBLIC-LIVE','{}')")
        ->execute(['org'=>$org,'src'=>$src]);
    $provenance=new MysqlRawMarketProvenance($pdo);
    $raws=new MysqlRawMarketEventRepository($pdo,$provenance);
    $received=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $raw=new RawMarketEvent('raw:fixture:'.bin2hex(random_bytes(7)),
        MarketSourceId::fromString($src),null,'BTC-USDT',
        'okx.spot.ticker.bbo',$received,$received,'76',
        ['ticker_json'=>'{"code":"0","data":[{"instType":"SPOT","instId":"BTC-USDT","bidPx":"100.01","bidSz":"1","askPx":"100.02","askSz":"1","vol24h":"25","ts":"1760000000123"}]}'],
        ['provider'=>'OKX','transport'=>'REST'],MarketDataMode::Live);
    $assert($raws->append($org,$raw),'Raw append failed.');
    $assert($raws->append($org,$raw)===false,'Duplicate raw event appended twice.');
    $list=$provenance->verify($org,$src,0,20);
    $assert($list['status']==='VERIFIED'&&$list['verified']===1
        &&$list['tampered']===0&&$list['missing']===0,
        'Stored raw SHA256 provenance could not be independently recomputed.');
    $assert($provenance->verifyEvent($org,$raw),'Rehydrated raw event has no valid provenance.');
    $foreign=$provenance->verify($org.'foreign',$src);
    $assert($foreign['checked']===0,'Foreign tenant accessed replay receipts.');
    $cursor=(int)$list['next_cursor'];
    $assert($provenance->verify($org,$src,$cursor)['checked']===0,
        'Cursor-based replay repeated already scanned events.');
    $reader=new MysqlCrossVenueBboReconciliation($pdo,new CrossVenueBboConsensus());
    $comparison=$reader->inspect($org,'instrument:missing');
    $assert($comparison['status']==='NO_QUORUM'
        &&$comparison['preferred_source_id']===null
        &&$comparison['for_execution']===false,
        'Unverified venue consensus manufactured fallback liquidity.');
    $receipt=$reader->record($org,$comparison);
    $check=$pdo->prepare(
        "SELECT outcome FROM tn_capital_market_venue_reconciliation
         WHERE organization_id=:org AND review_id=:id");
    $check->execute(['org'=>$org,'id'=>$receipt]);
    $assert($check->fetchColumn()==='NO_QUORUM',
        'No-quorum reconciliation receipt not durably recorded.');
    // Independent Replay normalization vs the persisted canonical baseline.
    $instrument='instrument:ci-btc';
    $pdo->prepare(
        "INSERT INTO tn_capital_market_instruments
         (organization_id,instrument_id,symbol,canonical_symbol,name,family,status,
          metadata_json,created_at,updated_at)
         VALUES (:org,:id,'BTC','BTC','Fixture Bitcoin','crypto_asset','ACTIVE','{}',
                 UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
        ->execute(['org'=>$org,'id'=>$instrument]);
    $obs=new class implements \Domains\CapitalMarkets\Domain\MarketData\MarketObservation{
        public function eventType():\Domains\CapitalMarkets\Domain\MarketData\MarketEventType{
            return \Domains\CapitalMarkets\Domain\MarketData\MarketEventType::Bbo;
        }
        public function toArray():array{return ['bid_price'=>'100.01','ask_price'=>'100.02'];}
    };
    $canonical=new \Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent(
        $raw->eventId,MarketSourceId::fromString($src),null,
        \Domains\CapitalMarkets\Domain\Instrument\InstrumentId::fromString($instrument),
        new \Domains\CapitalMarkets\Domain\MarketData\MarketTimestamps(
            $received,$received,$received),
        '76',$obs,[],1,MarketDataMode::Replay,
        \Domains\CapitalMarkets\Domain\MarketData\MarketStatus::Open
    );
    $comparator=new \Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlReplayCanonicalComparator($pdo);
    $assert($comparator->compare($org,$canonical)['status']==='NO_CANONICAL_BASELINE',
        'Missing baseline was mistaken for replay parity.');
    $stored=$pdo->prepare(
        "INSERT INTO tn_capital_market_canonical_events
         (organization_id,canonical_event_id,source_id,venue_id,instrument_id,
          event_type,source_timestamp,received_timestamp,processed_timestamp,
          sequence_value,payload_json,quality_flags_json,schema_version,
          fingerprint,data_mode,market_status)
         VALUES (:org,:event,:source,NULL,:instrument,'BBO',
                 :source_at,:received_at,:processed_at,'76','{}','[]',1,:digest,'LIVE','OPEN')");
    $params=['org'=>$org,'event'=>$raw->eventId,'source'=>$src,'instrument'=>$instrument,
        'source_at'=>$received->format('Y-m-d H:i:s.u'),
        'received_at'=>$received->format('Y-m-d H:i:s.u'),
        'processed_at'=>$received->format('Y-m-d H:i:s.u'),
        'digest'=>$canonical->fingerprint()];
    $stored->execute($params);
    // Full independent RAW -> decoder -> normalizer -> fingerprint,
    // without calling production ingestion/event bus/execution.
    $sourceDesc=new \Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor(
        MarketSourceId::fromString($src),null,
        \Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx\OkxSpotMarketDataAdapter::ADAPTER_TYPE,
        true,100,[\Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole::ValidationSource],
        null,new \Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy(10,2),
        new \Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy(),
        new \Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy(),[],'PUBLIC-LIVE');
    $sources=new class($sourceDesc) implements \Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface {
        public function __construct(public $source){}
        public function save(string $organizationId,\Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor $source):void{}
        public function get(string $organizationId,MarketSourceId $id):?\Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor{
            return $id->equals($this->source->id)?$this->source:null;
        }
        public function list(string $organizationId,bool $enabledOnly=false):array{return [$this->source];}
        public function saveHealth(string $organizationId,\Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth $health):void{}
        public function health(string $organizationId,MarketSourceId $id):?\Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth{return null;}
    };
    $normalizer=new class($obs,$instrument) implements \Domains\CapitalMarkets\Application\Contract\MarketDataNormalizerInterface {
        public function __construct(private $observation,private string $instrumentId){}
        public function normalize(string $organizationId,
            \Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor $source,
            RawMarketEvent $raw,
            \Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent $decoded
        ):\Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent{
            return new \Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent(
                $raw->eventId,$source->id,$source->venueId,
                \Domains\CapitalMarkets\Domain\Instrument\InstrumentId::fromString($this->instrumentId),
                new \Domains\CapitalMarkets\Domain\MarketData\MarketTimestamps(
                    $decoded->sourceTimestamp,$raw->receivedAt,$raw->receivedAt),
                $decoded->sequence,$this->observation,[],1,$decoded->mode,
                \Domains\CapitalMarkets\Domain\MarketData\MarketStatus::Open
            );
        }
    };
    $decoder=new \Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx\OkxSpotMarketDataDecoder(
        new \Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx\OkxPublicPayloadParser());
    $replayer=new \Domains\CapitalMarkets\Application\Service\RawMarketReplaySimulator(
        $raws,$sources,
        new \Domains\CapitalMarkets\Application\Service\MarketDataDecoderRegistry([$decoder]),
        $normalizer,$provenance,$comparator
    );
    $before=$pdo->query("SELECT COUNT(*) FROM tn_capital_market_canonical_events
       WHERE organization_id=".$pdo->quote($org))->fetchColumn();
    $range=$replayer->run($org,$src,
        $received->sub(new DateInterval('PT1S')),
        $received->add(new DateInterval('PT1S')),20);
    $assert($range['status']==='REPLAY_VERIFIED'
        &&$range['decoded']===1
        &&$range['events'][0]['canonical_integrity']==='CANONICAL_MATCH',
        'Full read-only replay did not preserve original canonical fingerprint.');
    $empty=$replayer->run($org,$src,
        $received->sub(new DateInterval('P4D')),
        $received->sub(new DateInterval('P3D')),20);
    $assert($empty['status']==='NO_EVIDENCE'
        &&$empty['has_evidence']===false&&$empty['decoded']===0,
        'Empty raw history falsely passed complete replay parity.');
    $extra=new RawMarketEvent('raw:ci:extra:'.bin2hex(random_bytes(8)),
        MarketSourceId::fromString($src),null,'BTC-USDT',
        'okx.spot.ticker.bbo',$received,
        $received->add(new DateInterval('PT1S')),'77',
        $raw->rawPayload,$raw->transportMetadata,MarketDataMode::Live);
    $assert($raws->append($org,$extra),'Second raw receipt could not be persisted.');
    $partial=$replayer->run($org,$src,
        $received->sub(new DateInterval('PT1S')),
        $received->add(new DateInterval('PT2S')),1);
    $assert($partial['status']==='REPLAY_PARTIAL'
        &&$partial['next_page_required']===true
        &&$partial['window_complete']===false
        &&count($partial['events'])===1,
        'Replay limit silently certified an incomplete time window.');

    $after=$pdo->query("SELECT COUNT(*) FROM tn_capital_market_canonical_events
       WHERE organization_id=".$pdo->quote($org))->fetchColumn();
    $assert($before===$after,'Read-only replay mutated canonical live stream.');
    $assert($comparator->compare($org,$canonical)['status']==='CANONICAL_MATCH',
        'Pure Replay must reproduce canonical fingerprint without ingestion.');
    $pdo->prepare("UPDATE tn_capital_market_canonical_events
         SET fingerprint=? WHERE organization_id=? AND canonical_event_id=?")
        ->execute([str_repeat('a',64),$org,$raw->eventId]);
    $assert($comparator->compare($org,$canonical)['status']==='CANONICAL_DRIFT',
        'Parser drift was not detected by historical canonical fingerprint.');
    $pdo->prepare("UPDATE tn_capital_market_canonical_events
         SET fingerprint=? WHERE organization_id=? AND canonical_event_id=?")
        ->execute([$canonical->fingerprint(),$org,$raw->eventId]);
    $pdo->prepare("UPDATE tn_capital_market_raw_events
       SET raw_payload_json='{\"ticker_json\":\"tampered\"}'
       WHERE organization_id=? AND raw_event_id=?")->execute([$org,$raw->eventId]);
    $tamperedReplay=$replayer->run($org,$src,
        $received->sub(new DateInterval('PT1S')),
        $received->add(new DateInterval('PT1S')),20);
    $assert($tamperedReplay['status']==='REPLAY_INCOMPLETE'
        &&$tamperedReplay['events'][0]['status']==='PROVENANCE_FAILED',
        'Tampered RAW frame slipped through replay path.');
    $changed=$provenance->verify($org,$src,0,20);
    $assert($changed['status']==='TAMPERED'&&$changed['tampered']===1,
        'Raw history tampering was not detected.');
    $assert($provenance->verifyEvent($org,$raw),
        'Original caller DTO integrity should remain valid independent of mutated stored value.');
    echo "Capital Markets MySQL raw provenance, cursor, tenancy, immutable reconciliation receipts and tamper detection passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach(['tn_capital_market_venue_reconciliation','tn_capital_market_canonical_events','tn_capital_market_raw_provenance',
        'tn_capital_market_raw_events','tn_capital_market_instruments','tn_capital_market_data_sources'] as $table){
        $pdo->prepare("DELETE FROM $table WHERE organization_id=:org")->execute(['org'=>$org]);
    }
}
