<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Application\Service\InstrumentPoolScheduleTargets;
require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $value,string $reason):void{if(!$value)throw new RuntimeException($reason);};
$two=InstrumentPoolScheduleTargets::parse('[{"organization_id":"tenant","provider":"BINANCE","venue_id":"binance"},{"venue_id":"kraken","provider":"KRAKEN","organization_id":"tenant"}]');
$assert(count($two)===2,'Configured targets were lost.');
$assert(InstrumentPoolScheduleTargets::parse('[]')===[],'Empty scheduler config must be safe.');
foreach([
    '{',
    '[{"organization_id":"a","provider":"BINANCE","venue_id":"v"},{"organization_id":"a","provider":"BINANCE","venue_id":"v"}]',
    '[{"organization_id":"a","provider":"UNKNOWN","venue_id":"v"}]',
    '[{"organization_id":"a","provider":"BINANCE","venue_id":"v","extra":true}]',
    '[{"organization_id":"a","provider":"BINANCE","venue_id":"v"},null]',
] as $invalid){
    $bad=false;
    try{InstrumentPoolScheduleTargets::parse($invalid);}catch(DomainException){$bad=true;}
    $assert($bad,'Unsafe scheduler config was accepted.');
}
echo "Instrument Pool scheduler target validation passed.\n";
