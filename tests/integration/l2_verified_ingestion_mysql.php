<?php
declare(strict_types=1);

/**
 * Requires isolated MySQL database and L2_TEST_MYSQL_DSN, USER, PASSWORD.
 * Uses production raw/canonical repositories and the actual market-event
 * migration; parent reference tables are deliberately minimal test fixtures.
 */
require dirname(__DIR__).'/unit/capital_markets_market_intelligence_ingestion.php';

use Domains\CapitalMarkets\Application\Service\{MarketDataDecoderRegistry,MarketDataIngestionService};
use Domains\CapitalMarkets\Domain\MarketData\{MarketSourceDescriptor,MarketSourceId,MarketSourceRole,RateLimitPolicy,ReconnectPolicy,MarketHealthPolicy,MarketConnectionState,MarketSourceHealth};
use Domains\CapitalMarkets\Domain\Service\{MarketDataQualityEngine,MarketStateEngine,OrderBookRebuilder,ReferenceMarketStateEngine};
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2VerifiedMarketDataDecoder,L2DomainBookFactory,L2IngestionPublisher,L2VerifiedIngestionCallback};
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\{MysqlRawMarketEventRepository,MysqlCanonicalMarketEventRepository,MarketDataHydrator};

$dsn=getenv('L2_TEST_MYSQL_DSN');
if (!$dsn || !str_starts_with($dsn,'mysql:')) throw new RuntimeException('Dedicated L2_TEST_MYSQL_DSN required.');
$pdo=new PDO($dsn,getenv('L2_TEST_MYSQL_USER')?:'',getenv('L2_TEST_MYSQL_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE IF NOT EXISTS tn_migrations (migration VARCHAR(190) PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS tn_capital_market_data_sources (
    organization_id VARCHAR(190) NOT NULL,source_id VARCHAR(190) NOT NULL,
    PRIMARY KEY (organization_id,source_id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS tn_capital_market_venues (
    organization_id VARCHAR(190) NOT NULL,venue_id VARCHAR(190) NOT NULL,
    PRIMARY KEY (organization_id,venue_id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS tn_capital_market_instruments (
    organization_id VARCHAR(190) NOT NULL,instrument_id VARCHAR(190) NOT NULL,
    PRIMARY KEY (organization_id,instrument_id)) ENGINE=InnoDB');
$migration=file_get_contents(dirname(__DIR__,2).'/app/migrations/20261006_000126_capital_markets_market_events.sql');
foreach (array_filter(array_map('trim',explode(';',$migration))) as $sql) $pdo->exec($sql);
$clock->time=new DateTimeImmutable('now');
$sourceId=MarketSourceId::fromString('source:l2-mysql');
$source=new MarketSourceDescriptor(
    $sourceId,$venueId,L2VerifiedMarketDataDecoder::ADAPTER_TYPE,true,10,
    [MarketSourceRole::TradingSource],null,new RateLimitPolicy(100,1),
    new ReconnectPolicy(),new MarketHealthPolicy(),[],'TEST',$qualityPolicy
);
$sources->save($organization,$source);
$sources->saveHealth($organization,new MarketSourceHealth(
    $sourceId,MarketConnectionState::Active,$clock->now(),$clock->now(),0,0,true
));
foreach ([
    ['tn_capital_market_data_sources','source_id',$sourceId->value()],
    ['tn_capital_market_venues','venue_id',$venueId->value()],
    ['tn_capital_market_instruments','instrument_id',$tokenId->value()]
] as [$table,$column,$id]) {
    $statement=$pdo->prepare("INSERT IGNORE INTO $table (organization_id,$column) VALUES (?,?)");
    $statement->execute([$organization,$id]);
}
$service=new MarketDataIngestionService(
    $sources,new MarketDataDecoderRegistry([new L2VerifiedMarketDataDecoder()]),
    $normalizer,new MysqlRawMarketEventRepository($pdo),
    new MysqlCanonicalMarketEventRepository($pdo,new MarketDataHydrator()),
    $qualityMetrics,$states,new MarketDataQualityEngine(),
    new MarketStateEngine(new OrderBookRebuilder()),new ReferenceMarketStateEngine(),
    $locks,$events,$transactions,$clock
);
$publisher=new L2IngestionPublisher($service,$organization,$sourceId,$venueId,'AAPLXUSDT');
$book=(new L2DomainBookFactory(new AssetCode('AAPLX'),new AssetCode('USDT')))
    ->fromFrame(['type'=>'snapshot','bids'=>[['price'=>'293.40','qty'=>'10']],
        'asks'=>[['price'=>'293.60','qty'=>'5']]],'99001');
(new L2VerifiedIngestionCallback($publisher))($book);
$raw=$pdo->query("SELECT sequence_value,raw_payload_json FROM tn_capital_market_raw_events ORDER BY id DESC LIMIT 1")->fetch();
$canonical=$pdo->query("SELECT sequence_value,event_type,payload_json FROM tn_capital_market_canonical_events ORDER BY id DESC LIMIT 1")->fetch();
if (!$raw || !$canonical || $raw['sequence_value']!=='99001'
    || $canonical['sequence_value']!=='99001'
    || $canonical['event_type']!=='ORDER_BOOK_SNAPSHOT'
    || json_decode($raw['raw_payload_json'],true)['book']['bids'][0]['quantity']!=='10'
    || json_decode($canonical['payload_json'],true)['bids'][0]['quantity']['value']!=='10') {
    throw new RuntimeException('MySQL L2 raw/canonical persistence mismatch.');
}
if ($states->get($organization,$venueId,$tokenId)===null) throw new RuntimeException('Canonical market state missing.');
echo "Verified L2 -> decoder -> normalizer -> MySQL raw/canonical -> market state passed\n";
