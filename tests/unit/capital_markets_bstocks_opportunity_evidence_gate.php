<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\CapitalMarkets\Application\Service\BStocksOpportunityEvidenceGate;
use Domains\CapitalMarkets\Domain\Instrument\{EconomicRelationship,EconomicRelationshipStrength,EconomicRelationshipType,EconomicRelationshipStatus,InstrumentId,RelationshipId};
$assert=static function(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);};
$at=new DateTimeImmutable('-1 day');
$make=static fn(array $m):EconomicRelationship=>new EconomicRelationship(
    RelationshipId::fromString('rel:bstocks:test'),
    InstrumentId::fromString('cm_underlying_1234'),
    InstrumentId::fromString('cm_bstock_1234'),
    EconomicRelationshipType::UnderlyingOf,EconomicRelationshipStrength::Direct,
    $at,null,EconomicRelationshipStatus::Active,$m
);
$gate=new BStocksOpportunityEvidenceGate();
$assert($gate->admit($make([]),'H1')==='BLOCKED','Ticker-only pool H1 must be blocked.');
$assert($gate->admit($make([]),'H2')==='BLOCKED','Unverified pool H2 must be blocked.');
$proof=['issuer_evidence_sha256'=>str_repeat('a',64),'evidence_tier'=>'E3',
    'underlying_verified'=>true,'unit_multiplier_verified'=>true,'corporate_action_version_verified'=>true];
$assert($gate->admit($make($proof),'H1')==='REFERENCE_ONLY','Verified E3 must stay reference only.');
$assert($gate->admit($make($proof),'H2')==='BLOCKED','E3 must not activate H2.');
$proof['evidence_tier']='E4';
$assert($gate->admit($make($proof),'H2')==='BLOCKED','Tier E4 alone does not prove route.');
$proof['route_verified']=true;$proof['fees_verified']=true;$proof['conversion_rights_verified']=true;
$assert($gate->admit($make($proof),'H2')==='VERIFIED_ROUTE','Full proof must still undergo downstream risk checks.');
echo "bStocks Opportunity evidence gates passed.\n";
