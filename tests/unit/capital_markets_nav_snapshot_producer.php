<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Contract\PortfolioValuationSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Application\Service\PortfolioNavSnapshotProducer;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$repository=new class implements PortfolioValuationSnapshotRepositoryInterface {
    public array $saved=[];
    public function append(string $organizationId,string $portfolioId,array $snapshot):void {
        $this->saved[]=['tenant'=>$organizationId,'portfolio'=>$portfolioId,'snapshot'=>$snapshot];
    }
    public function history(string $organizationId,string $portfolioId,DateTimeImmutable $from,DateTimeImmutable $to):array {return [];}
};
$producer=new PortfolioNavSnapshotProducer($repository);
$evidence=[
    'snapshot_id'=>'nav-test-1','valued_at'=>'2026-10-08T12:00:00Z','currency'=>'USD',
    'provenance_id'=>'ledger-run-1','ledger_fingerprint'=>'a','marks_fingerprint'=>'b','external_flows_fingerprint'=>'c',
    'ledger_reconciled'=>true,'marks_reconciled'=>true,'external_flows_reconciled'=>true,
    'cash_by_currency'=>[['currency'=>'USD','amount'=>'1100']],
    'liabilities_by_currency'=>[['currency'=>'USD','amount'=>'60']],
    'external_flows_by_currency'=>[['currency'=>'USD','amount'=>'300']],
    'marked_positions'=>[['position_id'=>'BTC-1','quote_currency'=>'USD','market_value'=>'240.50',
        'mark_reconciled'=>true,'mark_source_fingerprint'=>'mark-1']],
];
$result=$producer->record('org-a','paper-master',$evidence);
$assert($result['equity']==='1280.5','NAV must sum cash and marks net of liabilities with Decimal.');
$assert($result['cumulative_external_net_flow']==='300','External deposit ledger must be preserved.');
$assert($repository->saved[0]['tenant']==='org-a','Snapshot must remain tenant-scoped.');
$unsafe=$evidence;$unsafe['marks_reconciled']=false;
try {$producer->record('org-a','paper-master',$unsafe);throw new RuntimeException('Missing mark reconciliation was accepted');}
catch (InvalidArgumentException) {}
$unsafe=$evidence;$unsafe['marked_positions'][0]['quote_currency']='EUR';
try {$producer->record('org-a','paper-master',$unsafe);throw new RuntimeException('Unconverted mark was accepted');}
catch (InvalidArgumentException) {}
$unsafe=$evidence;$unsafe['cash_by_currency'][0]['currency']='EUR';
try {$producer->record('org-a','paper-master',$unsafe);throw new RuntimeException('Unconverted cash was accepted');}
catch (InvalidArgumentException) {}
$unsafe=$evidence;unset($unsafe['external_flows_fingerprint']);
try {$producer->record('org-a','paper-master',$unsafe);throw new RuntimeException('Missing ledger provenance was accepted');}
catch (InvalidArgumentException) {}
$assert(count($repository->saved)===1,'Invalid snapshots must never persist.');
echo "Capital Markets guarded NAV producer acceptance passed.\n";
