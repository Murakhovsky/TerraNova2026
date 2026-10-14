<?php
declare(strict_types=1);
$base = dirname(__DIR__,2).'/app/';
require_once $base.'Kernel/Shared/Domain/ValueObject.php';
foreach (['Decimal','AssetCode','Price','Quantity'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Value/'.$name.'.php';
foreach (['MarketEventType','MarketObservation','OrderBookLevel','MarketOrderBook'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/MarketData/'.$name.'.php';

foreach (['KrakenL2Checksum','KrakenL2BookRecovery'] as $name) require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/'.$name.'.php';
foreach (['L2FrameSizeGuard','L2ControlFrameFilter','L2WebSocketFrameRouter','L2FrameDispatch','L2DomainBookFactory','KrakenL2StreamProcessor'] as $name) require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/'.$name.'.php';
use Domains\CapitalMarkets\Domain\Value\AssetCode;

use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{KrakenL2Checksum,KrakenL2BookRecovery};
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2FrameSizeGuard,L2ControlFrameFilter,L2WebSocketFrameRouter,L2FrameDispatch,L2DomainBookFactory,KrakenL2StreamProcessor};
$checksum = new KrakenL2Checksum();
$processor = new KrakenL2StreamProcessor(
    new L2FrameDispatch(new L2FrameSizeGuard(),new L2ControlFrameFilter(),new L2WebSocketFrameRouter('KRAKEN_SPOT','BTC/USD'),'KRAKEN_SPOT'),
    new L2DomainBookFactory(new AssetCode('BTC'),new AssetCode('USD')),
    new KrakenL2BookRecovery($checksum,10)
);
$frame = static function(string $type,array $bids,array $asks,string $crc):string {
    return json_encode(['channel'=>'book','type'=>$type,'data'=>[['symbol'=>'BTC/USD','bids'=>$bids,'asks'=>$asks,'checksum'=>(int)$crc]]],JSON_THROW_ON_ERROR);
};
$bids=[['price'=>'100','qty'=>'2']];$asks=[['price'=>'110','qty'=>'1']];
$processor->begin();
$processor->receive($frame('snapshot',$bids,$asks,$checksum->calculate($asks,$bids)));
if ($processor->trustedBook() === null) throw new RuntimeException('Kraken verified snapshot missing.');
$bids=[['price'=>'100','qty'=>'3']];
$processor->receive($frame('update',$bids,[],$checksum->calculate($asks,$bids)));
if ($processor->trustedBook()?->bids[0]->quantity->value->value() !== '3') throw new RuntimeException('Kraken delta missing.');
$failed=false;
try { $processor->receive($frame('update',[],[], '0')); } catch (DomainException $e) { $failed=true; }
if (!$failed || $processor->trustedBook() !== null) throw new RuntimeException('Kraken checksum failure did not revoke trust.');
echo "Kraken L2 stream processor smoke passed\n";
