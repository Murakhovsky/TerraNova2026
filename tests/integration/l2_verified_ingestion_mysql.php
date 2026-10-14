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
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\{MysqlRawMarketEventRepository,MysqlCanonicalMarketEventRepository,MysqlMarketQualityMetricRepository,MysqlMarketStateRepository,MarketDataHydrator};

$dsn=getenv('L2_TEST_MYSQL_DSN');
if (!$dsn || !str_starts_with($dsn,'mysql:')) throw new RuntimeException('Dedicated L2_TEST_MYSQL_DSN required.');
$pdo=new PDO($dsn,getenv('L2_TEST_MYSQL_USER')?:'',getenv('L2_TEST_MYSQL_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE IF NOT EXISTS tn_migrations (migration VARCHAR(190) PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS tn_capital_market_data_sources (
    organization_id VARCHAR(190) NOT NULL,source_id VARCHAR(190) NOT NULL,
    PRIMARY KEY (organization_id,source_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE IF NOT EXISTS tn_capital_market_venues (
    organization_id VARCHAR(190) NOT NULL,venue_id VARCHAR(190) NOT NULL,
    PRIMARY KEY (organization_id,venue_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE IF NOT EXISTS tn_capital_market_instruments (
    organization_id VARCHAR(190) NOT NULL,instrument_id VARCHAR(190) NOT NULL,
    PRIMARY KEY (organization_id,instrument_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$migration=file_get_contents(dirname(__DIR__,2).'/app/migrations/20261006_000126_capital_markets_market_events.sql');
foreach (array_filter(array_map('trim',explode(';',$migration))) as $sql) $pdo->exec($sql);
$stateMigration=file_get_contents(dirname(__DIR__,2).'/app/migrations/20261006_000127_capital_markets_market_state.sql');
foreach (array_filter(array_map('trim',explode(';',$stateMigration))) as $sql) {
    if (str_starts_with($sql,'CREATE TABLE')) $pdo->exec($sql);
}
$organization='l2-mysql-'.bin2hex(random_bytes(8));
$clock->time=new DateTimeImmutable('+10 seconds');
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
    new MysqlMarketQualityMetricRepository($pdo),
    new MysqlMarketStateRepository($pdo,new MarketDataHydrator()),new MarketDataQualityEngine(),
    new MarketStateEngine(new OrderBookRebuilder()),new ReferenceMarketStateEngine(),
    $locks,$events,new class($pdo) implements \Kernel\Transaction\Contract\TransactionManagerInterface {
        private array $afterCommit=[];
        public function __construct(private PDO $connection) {}
        public function transactional(callable $operation):mixed {
            if ($this->connection->inTransaction()) return $operation();
            $this->connection->beginTransaction();
            try {
                $result=$operation();
                $this->connection->commit();
                $callbacks=$this->afterCommit;
                $this->afterCommit=[];
                foreach ($callbacks as $callback) $callback();
                return $result;
            } catch (Throwable $error) {
                if ($this->connection->inTransaction()) $this->connection->rollBack();
                $this->afterCommit=[];
                throw $error;
            }
        }
        public function isActive():bool { return $this->connection->inTransaction(); }
        public function afterCommit(callable $callback):void {
            if ($this->connection->inTransaction()) { $this->afterCommit[]=$callback; return; }
            $callback();
        }
    },$clock
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
$persistedState=(new MysqlMarketStateRepository($pdo,new MarketDataHydrator()))->get($organization,$venueId,$tokenId);
if ($persistedState === null || $persistedState->lastSequence !== '99001'
    || $persistedState->orderBook === null) {
    throw new RuntimeException('Persisted canonical market state missing or invalid.');
}
$metrics=$pdo->query("SELECT canonical_event_id,trust_status FROM tn_capital_market_quality_metrics ORDER BY id DESC LIMIT 1")->fetch();
if (!$metrics || $metrics['canonical_event_id'] === '') {
    throw new RuntimeException('MySQL quality metrics not persisted.');
}
$rawRepository=new MysqlRawMarketEventRepository($pdo);
$canonicalRepository=new MysqlCanonicalMarketEventRepository($pdo,new MarketDataHydrator());
$history=$canonicalRepository->history(
    $organization,$tokenId,$clock->now()->modify('-1 minute'),$clock->now()->modify('+1 minute')
);
if (count($history)!==1 || $history[0]->sequence!=='99001') {
    throw new RuntimeException('Canonical history round-trip failed.');
}
$rawHistory=$rawRepository->range(
    $organization,$sourceId,$clock->now()->modify('-1 minute'),$clock->now()->modify('+1 minute')
);
if (count($rawHistory)!==1 || $rawHistory[0]->sequence!=='99001') {
    throw new RuntimeException('Raw event round-trip failed.');
}
if ($rawRepository->append($organization,$rawHistory[0])
    || $canonicalRepository->append($organization,$history[0])) {
    throw new RuntimeException('MySQL duplicate events were not rejected.');
}
$countQuery=$pdo->prepare("SELECT
    (SELECT COUNT(*) FROM tn_capital_market_raw_events WHERE organization_id=:raw_org) AS raw_count,
    (SELECT COUNT(*) FROM tn_capital_market_canonical_events WHERE organization_id=:canonical_org) AS canonical_count,
    (SELECT COUNT(*) FROM tn_capital_market_quality_metrics WHERE organization_id=:quality_org) AS quality_count");
$countQuery->execute(['raw_org'=>$organization,'canonical_org'=>$organization,'quality_org'=>$organization]);
$counts=$countQuery->fetch();
if ((int)$counts['raw_count']!==1 || (int)$counts['canonical_count']!==1
    || (int)$counts['quality_count']!==1) {
    throw new RuntimeException('Unexpected MySQL ingestion row counts.');
}
echo "Verified L2 -> decoder -> normalizer -> MySQL raw/canonical/quality/state passed\n";
