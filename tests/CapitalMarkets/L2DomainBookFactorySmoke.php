<?php
declare(strict_types=1);
$base = dirname(__DIR__, 2).'/app/';
require_once $base.'Kernel/Shared/Domain/ValueObject.php';
foreach (['Decimal','AssetCode','Price','Quantity'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/Value/'.$name.'.php';
foreach (['MarketEventType','OrderBookLevel','MarketOrderBook'] as $name) require_once $base.'Domains/CapitalMarkets/Domain/MarketData/'.$name.'.php';
require_once $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2DomainBookFactory.php';
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2DomainBookFactory;
$f = new L2DomainBookFactory(new AssetCode('BTC'), new AssetCode('USDT'));
$book = $f->fromFrame(['type'=>'delta','bids'=>[['price'=>'100.000','qty'=>'0.000']],'asks'=>[]], '42');
if ($book->sequence !== '42' || !$book->bids[0]->quantity->value->isZero()) throw new RuntimeException('L2 typed conversion failed.');
$rejected = false;
try { $f->fromFrame(['type'=>'delta','bids'=>[['price'=>'0','qty'=>'1']],'asks'=>[]]); } catch (\Throwable $e) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Zero price accepted.');
echo "L2 domain book factory smoke passed\n";
