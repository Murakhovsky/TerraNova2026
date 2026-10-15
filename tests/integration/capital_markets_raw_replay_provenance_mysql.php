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
        ['ticker_json'=>'{"code":"0","bidPx":"100.01","askPx":"100.02"}'],
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
    $pdo->prepare("UPDATE tn_capital_market_raw_events
       SET raw_payload_json='{\"ticker_json\":\"tampered\"}'
       WHERE organization_id=? AND raw_event_id=?")->execute([$org,$raw->eventId]);
    $changed=$provenance->verify($org,$src,0,20);
    $assert($changed['status']==='TAMPERED'&&$changed['tampered']===1,
        'Raw history tampering was not detected.');
    $assert($provenance->verifyEvent($org,$raw),
        'Original caller DTO integrity should remain valid independent of mutated stored value.');
    echo "Capital Markets MySQL raw provenance, cursor, tenancy, immutable reconciliation receipts and tamper detection passed.\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach(['tn_capital_market_venue_reconciliation','tn_capital_market_raw_provenance',
        'tn_capital_market_raw_events','tn_capital_market_data_sources'] as $table){
        $pdo->prepare("DELETE FROM $table WHERE organization_id=:org")->execute(['org'=>$org]);
    }
}
