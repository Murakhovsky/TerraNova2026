<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Opportunity\TokenizedEquityUniverse;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$u=TokenizedEquityUniverse::fromArray([
    'id'=>'core-us-xstocks',
    'name'=>'Core US xStocks',
    'status'=>'ACTIVE',
    'underlying_instrument_ids'=>['instrument:aapl','instrument:nvda'],
    'tokenized_instrument_ids'=>['instrument:aaplx','instrument:nvdax'],
    'venues'=>['venue:bybit','venue:kraken'],
    'hypotheses'=>['H1','H2'],
    'enabled'=>true,
]);

$assert($u->allowsUnderlying('instrument:aapl'),'Curated universe must allow configured underlying.');
$assert(!$u->allowsUnderlying('instrument:msft'),'Curated universe must reject unconfigured underlying.');
$assert($u->allowsToken('instrument:aaplx'),'Curated universe must allow configured token.');
$assert(!$u->allowsToken('instrument:msftx'),'Curated universe must reject unconfigured token.');
$assert($u->allowsVenue('venue:kraken'),'Curated universe must allow configured venue.');
$assert(!$u->allowsVenue('venue:unknown'),'Curated universe must reject unconfigured venue.');
$assert($u->allowsHypothesis('H1')&&$u->allowsHypothesis('H2'),'Curated universe hypothesis filtering drifted.');

$disabled=TokenizedEquityUniverse::fromArray(['enabled'=>false]);
$assert(!$disabled->enabled,'Disabled universe flag drifted.');
$assert($disabled->underlyingInstrumentIds===[]&&$disabled->venues===[],'Default universe filters must remain empty, not hardcoded.');

$failed=false;
try{
    TokenizedEquityUniverse::fromArray([
        'hypotheses'=>['H3'],
    ]);
}catch(InvalidArgumentException){$failed=true;}
$assert($failed,'Unknown universe hypothesis must fail closed.');

echo "Capital Markets Tokenized Equity curated universe passed.\n";
