<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$controller=file_get_contents($root.'/symfony/src/Web/CapitalMarkets/CapitalMarketsPageController.php');
$template=file_get_contents($root.'/symfony/templates/experience/capital_markets/workspace.html.twig');
$ci=file_get_contents($root.'/.github/workflows/ci.yml');
if(!is_string($controller)||!is_string($template)||!is_string($ci)){
    throw new RuntimeException('Streaming UI contract files unavailable.');
}
foreach(['CapitalMarketsCapability::MarketDataView','readStreamingOperations($org)',
    '$this->streamingOps->inspect($org)','$this->streamingHealth->evaluate($snapshot)',
    "'health'=>'UNAVAILABLE'", "'streaming_ops'"] as $term){
    if(!str_contains($controller,$term))throw new RuntimeException('Streaming workspace safety missing '.$term);
}
foreach(['Streaming operations (read only)','stream.lease_counts.ACTIVE',
    'stream.lease_counts.EXPIRED','stream.lease_counts.RELEASED',
    'streamHealth.alerts','No worker lease evidence available.'] as $term){
    if(!str_contains($template,$term))throw new RuntimeException('Streaming operations UI missing '.$term);
}
if(!str_contains($ci,'capital_markets_streaming_workspace_contract.php')){
    throw new RuntimeException('Streaming operator UI contract missing from CI.');
}
echo "Tenant-scoped read-only streaming workspace contracts passed.\n";
