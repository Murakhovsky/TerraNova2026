<?php
declare(strict_types=1);

/**
 * Real ingestion service integration using the existing canonical test
 * fixture repositories. Does NOT substitute for MySQL persistence testing.
 */
require dirname(__DIR__).'/unit/capital_markets_market_intelligence_ingestion.php';

use Domains\CapitalMarkets\Application\Service\{MarketDataDecoderRegistry,MarketDataIngestionService};
use Domains\CapitalMarkets\Domain\MarketData\{MarketSourceDescriptor,MarketSourceId,MarketSourceRole,RateLimitPolicy,ReconnectPolicy,MarketHealthPolicy,MarketConnectionState,MarketSourceHealth};
use Domains\CapitalMarkets\Domain\Service\{MarketDataQualityEngine,MarketStateEngine,OrderBookRebuilder,ReferenceMarketStateEngine};
use Domains\CapitalMarkets\Domain\Value\{AssetCode};
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2VerifiedMarketDataDecoder,L2DomainBookFactory,L2IngestionPublisher,L2VerifiedIngestionCallback};

$clock->time = new DateTimeImmutable('+10 seconds');
$sourceId = MarketSourceId::fromString('source:l2-verified-integration');
$source = new MarketSourceDescriptor(
    $sourceId,$venueId,L2VerifiedMarketDataDecoder::ADAPTER_TYPE,true,10,
    [MarketSourceRole::TradingSource],null,new RateLimitPolicy(100,1),
    new ReconnectPolicy(),new MarketHealthPolicy(),[],'TEST',$qualityPolicy
);
$sources->save($organization,$source);
$sources->saveHealth($organization,new MarketSourceHealth(
    $sourceId,MarketConnectionState::Active,$clock->now(),$clock->now(),0,0,true
));
$service = new MarketDataIngestionService(
    $sources,new MarketDataDecoderRegistry([new L2VerifiedMarketDataDecoder()]),
    $normalizer,$raws,$canonical,$qualityMetrics,$states,
    new MarketDataQualityEngine(),new MarketStateEngine(new OrderBookRebuilder()),
    new ReferenceMarketStateEngine(),$locks,$events,$transactions,$clock
);
$publisher = new L2IngestionPublisher($service,$organization,$sourceId,$venueId,'AAPLXUSDT');
$factory = new L2DomainBookFactory(new AssetCode('AAPLX'),new AssetCode('USDT'));
$book = $factory->fromFrame([
    'type'=>'snapshot','bids'=>[['price'=>'293.40','qty'=>'10']],
    'asks'=>[['price'=>'293.60','qty'=>'5']],
],'9001');
(new L2VerifiedIngestionCallback($publisher))($book);
$raw = end($raws->events);
$canonicalEvent = end($canonical->events);
if ($raw->eventType !== 'l2.verified.snapshot'
    || $raw->rawPayload['book']['bids'][0]['quantity'] !== '10'
    || $canonicalEvent->sequence !== '9001'
    || $canonicalEvent->eventType()->value !== 'ORDER_BOOK_SNAPSHOT') {
    throw new RuntimeException('Verified L2 end-to-end ingestion failed.');
}
echo "L2 verified canonical ingestion integration passed\n";
