<?php
declare(strict_types=1);
$base = dirname(__DIR__,2).'/app/';
require_once $base.'Kernel/Shared/Domain/ValueObject.php';
require_once $base.'Kernel/Shared/Domain/Identifier.php';
foreach (['Decimal','AssetCode','Price','Quantity'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Value/'.$name.'.php';
foreach (['MarketEventType','MarketObservation','OrderBookLevel','MarketOrderBook','MarketDataMode','MarketSourceId','RawMarketEvent'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/MarketData/'.$name.'.php';
require_once $base.'Domains/CapitalMarkets/Domain/Venue/VenueId.php';
require_once $base.'Domains/CapitalMarkets/Application/DTO/MarketDataIngestionResult.php';
require_once $base.'Domains/CapitalMarkets/Application/Contract/MarketDataIngestionInterface.php';
require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2DomainBookFactory.php';
require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2IngestionPublisher.php';
use Domains\CapitalMarkets\Application\Contract\MarketDataIngestionInterface;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use Domains\CapitalMarkets\Domain\MarketData\{MarketSourceId,RawMarketEvent};
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2DomainBookFactory,L2IngestionPublisher};
class L2CaptureIngestion implements MarketDataIngestionInterface {
    public ?RawMarketEvent $received = null;
    public function ingest(string $organizationId, RawMarketEvent $raw): MarketDataIngestionResult {
        if ($organizationId !== 'test-org') throw new RuntimeException('Wrong organization.');
        $this->received = $raw;
        return new MarketDataIngestionResult('ACCEPTED',true,true,true);
    }
}
$adapter = new L2CaptureIngestion();
$publisher = new L2IngestionPublisher($adapter,'test-org',MarketSourceId::fromString('test-source'),null,'BTCUSDT','binance.spot.depth.snapshot');
$factory = new L2DomainBookFactory(new AssetCode('BTC'),new AssetCode('USDT'));
$book = $factory->fromFrame(['type'=>'snapshot','bids'=>[['price'=>'100','qty'=>'2']],'asks'=>[['price'=>'101','qty'=>'1']]],'42');
$result = $publisher->publish($book);
if ($result->status !== 'ACCEPTED' || $adapter->received?->sequence !== '42'
    || $adapter->received?->transportMetadata['recovery'] !== 'VERIFIED') {
    throw new RuntimeException('L2 ingestion bridge regression.');
}
echo "L2 ingestion publisher smoke passed\n";
