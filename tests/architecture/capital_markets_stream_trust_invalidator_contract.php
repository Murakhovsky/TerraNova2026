<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$src=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingTrustInvalidator.php');
if(!is_string($src))throw new RuntimeException('Trust invalidator missing.');
foreach(['FOR UPDATE','fencing_token=:token','tn_capital_market_states',
'tn_capital_market_reference_states',"'UNTRUSTED'",'state_json=:json','rollBack()'] as $value){
 if(!str_contains($src,$value))throw new RuntimeException('Missing streaming trust invalidation invariant '.$value);
}
echo "Capital Markets streaming trust invalidation contract passed.\n";
