<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Application\Service\InstrumentPoolIssuerE4Policy;
require dirname(__DIR__,2).'/vendor/autoload.php';
$sha=str_repeat('b',64);
$meta=['evidence_tier'=>'E4','issuer_evidence_sha256'=>$sha,
 'underlying_verified'=>true,'unit_multiplier_verified'=>true,
 'corporate_action_version_verified'=>true,'route_verified'=>true,
 'fees_verified'=>true,'conversion_rights_verified'=>true];
if(!InstrumentPoolIssuerE4Policy::valid($meta,$sha))throw new RuntimeException('E4 rejected.');
foreach(array_keys($meta) as $field){
    $bad=$meta;
    $bad[$field]=$field==='issuer_evidence_sha256'?str_repeat('a',64):false;
    if(InstrumentPoolIssuerE4Policy::valid($bad,$sha))throw new RuntimeException('E4 bypass: '.$field);
}
if(InstrumentPoolIssuerE4Policy::valid($meta,str_repeat('x',64)))throw new RuntimeException('Invalid SHA.');
echo "Issuer E4 reviewed digest and six mandatory relationship claims accepted.\n";
