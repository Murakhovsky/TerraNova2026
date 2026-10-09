<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PortfolioNavFinancialEvidencePolicy;

require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); };
$record=[
    'evidence_id'=>'flow-1','kind'=>'EXTERNAL_CASH_FLOW',
    'currency'=>'USD','amount'=>'-25.375','provider_id'=>'bank-statement',
    'source_reference'=>'statement-2026-001','source_document_sha256'=>str_repeat('a',64),
    'collected_by'=>'operator-123','effective_at'=>'2026-10-01T12:00:00Z',
    'status'=>'COMPLETE','reconciled'=>true,
];
$flow=PortfolioNavFinancialEvidencePolicy::normalize($record);
$assert($flow['amount']==='-25.375','Outflows must retain signed exact decimal value.');
$assert(strlen($flow['source_key_sha256'])===64,'Every accounting observation needs deterministic source identity.');
$assert($flow['status']==='PENDING_RECONCILIATION' && $flow['reconciled']===false,
    'User-supplied reconciliation or approval must never grant ledger authority.');
$bad=$record;$bad['amount']='0';
try {PortfolioNavFinancialEvidencePolicy::normalize($bad);throw new RuntimeException('Zero cash flow was accepted');}
catch (InvalidArgumentException) {}
$bad=$record;$bad['source_document_sha256']='bad';
try {PortfolioNavFinancialEvidencePolicy::normalize($bad);throw new RuntimeException('Non-provenanced record was accepted');}
catch (InvalidArgumentException) {}
$bad=$record;$bad['kind']='LIABILITY_BALANCE';
try {PortfolioNavFinancialEvidencePolicy::normalize($bad);throw new RuntimeException('Negative liability was accepted');}
catch (InvalidArgumentException) {}
$validDebt=$record;
$validDebt['kind']='LIABILITY_BALANCE';
$validDebt['amount']='0';
$validDebt['liability_account_id']='credit-line-a';
$debt=PortfolioNavFinancialEvidencePolicy::normalize($validDebt);
$assert($debt['liability_account_id']==='credit-line-a',
    'Balance snapshots require stable account identity for time-series replacement.');
unset($validDebt['liability_account_id']);
try {PortfolioNavFinancialEvidencePolicy::normalize($validDebt);throw new RuntimeException('Unidentified liability account accepted');}
catch (InvalidArgumentException) {}

$bad=$record;$bad['kind']='VENUE_BALANCE';$bad['amount']='120';
try {PortfolioNavFinancialEvidencePolicy::normalize($bad);throw new RuntimeException('Venue not identified');}
catch (InvalidArgumentException) {}
$bad=$record;$bad['kind']='VENUE_BALANCE';$bad['amount']='120';$bad['venue_id']='venue-2';
$venue=PortfolioNavFinancialEvidencePolicy::normalize($bad);
$assert($venue['kind']==='VENUE_BALANCE' && $venue['amount']==='120','Venue statement should normalize without asserting matching account balance.');
$bad=$record;$bad['effective_at']='yesterday';
try {PortfolioNavFinancialEvidencePolicy::normalize($bad);throw new RuntimeException('Ambiguous effective time accepted');}
catch (InvalidArgumentException) {}
$bad=$record;$bad['kind']='EXPENSE';
try {PortfolioNavFinancialEvidencePolicy::normalize($bad);throw new RuntimeException('Unknown accounting evidence type accepted');}
catch (InvalidArgumentException) {}
$custody=$record;
$custody['kind']='POSITION_BALANCE';$custody['amount']='2.5';
$custody['venue_id']='venue-2';$custody['position_id']='position-1';
$custody['instrument_id']='AAPLx';
$positionEvidence=PortfolioNavFinancialEvidencePolicy::normalize($custody);
$assert($positionEvidence['position_id']==='position-1'
    && $positionEvidence['instrument_id']==='AAPLx',
    'Independent custody statement must carry both stable position and instrument identity.');
$badCustody=$custody;unset($badCustody['position_id']);
try {PortfolioNavFinancialEvidencePolicy::normalize($badCustody);throw new RuntimeException('Custody without position ID accepted');}
catch (InvalidArgumentException) {}
$coverage=$record;$coverage['kind']='ACCOUNT_COVERAGE';$coverage['amount']='0';
$coverage['coverage_scope']='EXTERNAL_FLOWS';
$coverage['coverage_from']='1970-01-01T00:00:00Z';
$coverage['coverage_through']='2026-10-09T09:00:00Z';$coverage['all_accounts']=true;
$normalizedCoverage=PortfolioNavFinancialEvidencePolicy::normalize($coverage);
$assert($normalizedCoverage['coverage_scope']==='EXTERNAL_FLOWS' && $normalizedCoverage['all_accounts']===true,
    'Zero-flow history requires explicit full-account scope coverage.');
$badCoverage=$coverage;$badCoverage['all_accounts']=false;
try {PortfolioNavFinancialEvidencePolicy::normalize($badCoverage);throw new RuntimeException('Incomplete account coverage accepted');}
catch (InvalidArgumentException) {}
echo "Capital Markets NAV financial source evidence policy passed.\n";
