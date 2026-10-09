<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Contract\PortfolioNavFinancialEvidenceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\PortfolioValuationSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Application\Service\PortfolioNavEvidenceCollector;
use Domains\CapitalMarkets\Application\Service\PortfolioNavFinancialEvidencePolicy;
use Domains\CapitalMarkets\Application\Service\PortfolioNavIndependentReconciliationService;
use Domains\CapitalMarkets\Application\Service\PortfolioNavSnapshotProducer;
use Domains\CapitalMarkets\Application\Service\PortfolioNavWindowProjector;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $yes,string $why):void {if(!$yes)throw new RuntimeException($why);};

$trading = new class implements \Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface {
    public array $balances = [['venue_id'=>'VENUE-1','asset_key'=>'USD','available_amount'=>'90','reserved_amount'=>'10']];
    public array $positions = [];
    public array $ledger = [];
    public function saveCandidate(string $organizationId,string $candidateId,string $hypothesis,string $status,array $payload):void { return; }
    public function saveOpportunity(string $organizationId,string $opportunityId,string $candidateId,string $hypothesis,string $status,array $payload):void { return; }
    public function getOpportunity(string $organizationId,string $opportunityId):?array { return null; }
    public function saveRiskAssessment(string $organizationId,string $riskId,string $opportunityId,string $decision,array $payload):void { return; }
    public function saveExecution(string $organizationId,string $executionId,string $opportunityId,string $status,array $payload):void { return; }
    public function saveExecutionPlan(string $organizationId,string $planId,string $opportunityId,array $payload):void { return; }
    public function savePaperOrder(string $organizationId,string $orderId,string $executionId,string $legId,string $state,string $idempotencyKey,array $payload):void { return; }
    public function savePaperFill(string $organizationId,string $fillId,string $orderId,string $executionId,string $idempotencyKey,array $payload):void { return; }
    public function savePosition(string $organizationId,string $positionId,string $portfolioId,string $strategyId,string $instrumentId,string $venueId,string $status,array $payload):void { return; }
    public function listPositions(string $organizationId,int $limit=500):array { return $this->positions; }
    public function listExecutions(string $organizationId,int $limit=500):array { return []; }
    public function listLedgerTransactions(string $organizationId,int $limit=500):array { return $this->ledger; }
    public function getExecutionForOpportunity(string $organizationId,string $opportunityId):?array { return null; }
    public function getExecution(string $organizationId,string $executionId):?array { return null; }
    public function listPaperOrdersForExecution(string $organizationId,string $executionId):array { return []; }
    public function listPaperFillsForExecution(string $organizationId,string $executionId):array { return []; }
    public function ledgerTransactionExists(string $organizationId,string $idempotencyKey):bool { return false; }
    public function saveLedgerTransaction(string $organizationId,string $transactionId,string $idempotencyKey,array $payload):void { return; }
    public function initializePaperPortfolio(string $organizationId,string $currency,string $initialCapital):array { return []; }
    public function paperPortfolio(string $organizationId):?array { return ['currency'=>'USD']; }
    public function reserveCapital(string $organizationId,string $reservationId,string $opportunityId,string $amount,string $expiresAt):bool { return false; }
    public function getCapitalReservation(string $organizationId,string $reservationId):?array { return null; }
    public function listCapitalReservations(string $organizationId,?string $status=null,int $limit=1000):array { return []; }
    public function releaseReservation(string $organizationId,string $reservationId):void { return; }
    public function completeReservation(string $organizationId,string $reservationId,string $realizedPnl):void { return; }
    public function setPaperBalance(string $organizationId,string $venueId,string $assetKey,string $amount):void { return; }
    public function listPaperBalances(string $organizationId):array { return $this->balances; }
    public function reservePaperBalance(string $organizationId,string $reservationId,string $opportunityId, string $venueId,string $assetKey,string $amount,string $expiresAt):bool { return false; }
    public function releasePaperBalanceReservation(string $organizationId,string $reservationId):void { return; }
    public function consumePaperBalanceReservation(string $organizationId,string $reservationId):void { return; }
    public function creditPaperBalance(string $organizationId,string $venueId,string $assetKey,string $amount):void { return; }
    public function adjustPaperBalance(string $organizationId,string $venueId,string $assetKey,string $delta):void { return; }
    public function settlePaperExecution(string $organizationId,string $executionId,string $capitalReservationId,string $buyCashReservationId, ?string $sellInventoryReservationId,string $buyVenueId,string $buyInstrumentId,string $buyQuantity, string $sellVenueId,string $quoteAsset,string $sellCash,string $realizedPnl, ?string $compensationQuantity=null,?string $compensationCash=null):void { return; }
    public function listOpportunities(string $organizationId,int $limit=200):array { return []; }
    public function saveHypothesisObservation(string $organizationId,string $observationId,string $hypothesis,string $stage,string $observedAt, string $fingerprint,array $payload):void { return; }
    public function listHypothesisObservations(string $organizationId,?string $hypothesis=null,int $limit=10000):array { return []; }
    public function dashboard(string $organizationId):array { return []; }
};

$market = new class implements \Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface {
    public function save(string $organizationId,MarketState $state):void { return; }
    public function get(string $organizationId,VenueId $venueId,InstrumentId $instrumentId):?MarketState { return null; }
    public function list(string $organizationId,int $limit=200):array { return []; }
    public function saveReference(string $organizationId,ReferenceMarketState $state):void { return; }
    public function getReference(string $organizationId,MarketSourceId $sourceId,InstrumentId $instrumentId):?ReferenceMarketState { return null; }
    public function listReferences(string $organizationId,int $limit=200):array { return []; }
};

$trading->ledger = [[
        'transaction_id'=>'trade-ledger-1', 'posted_at'=>(new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
        'entries'=>[
            ['asset_key'=>'USD','account'=>'cash','debit'=>'100','credit'=>'0'],
            ['asset_key'=>'USD','account'=>'equity','debit'=>'0','credit'=>'100'],
        ],
    ]];

$source = new class implements PortfolioNavFinancialEvidenceRepositoryInterface {
    public array $records=[];
    public function append(string $organizationId,string $portfolioId,array $observation):void {
        $this->records[]=PortfolioNavFinancialEvidencePolicy::normalize($observation);
    }
    public function forPortfolio(string $organizationId,string $portfolioId,int $limit=2000):array {
        return $this->records;
    }
};
$repository = new class implements PortfolioValuationSnapshotRepositoryInterface {
    public array $saved=[];
    public function append(string $organizationId,string $portfolioId,array $snapshot):void {
        if ($organizationId!=='org-a' || $portfolioId!=='paper-master') throw new RuntimeException('Scope failure.');
        $this->saved[]=$snapshot;
    }
    public function history(string $organizationId,string $portfolioId,DateTimeImmutable $from,DateTimeImmutable $to):array {
        return $this->saved;
    }
};
$access = new class implements CapitalMarketsAccessControlInterface {
    public function hasCapability(string $organizationId,int $userId,string $capability):bool {
        return $organizationId==='org-a' && $userId===77 && $capability==='capital_markets.manage';
    }
};
$collector=new PortfolioNavEvidenceCollector($trading,$market,$source);
$service=new PortfolioNavIndependentReconciliationService(
    $trading,$source,$collector,new PortfolioNavSnapshotProducer($repository),$access
);
$now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM);
$fact=static function(string $kind,string $id,string $amount,array $more=[])use($now):array {
    return [
        'evidence_id'=>$id, 'kind'=>$kind, 'amount'=>$amount,
        'currency'=>'USD','provider_id'=>'independent-issuer',
        'source_reference'=>'file:'.$id,
        'source_document_sha256'=>hash('sha256','independent-document:'.$id),
        'collected_by'=>'21','effective_at'=>$now,
        ...$more,
    ];
};
$source->append('org-a','paper-master',$fact('VENUE_BALANCE','venue-statement','100',['venue_id'=>'VENUE-1']));
$source->append('org-a','paper-master',$fact('EXTERNAL_CASH_FLOW','external-deposit','100'));
$source->append('org-a','paper-master',$fact('LIABILITY_BALANCE','zero-debt','0',['liability_account_id'=>'LOAN-1']));
$cover=[
    'coverage_from'=>'1970-01-01T00:00:00Z',
    'coverage_through'=>$now,
    'all_accounts'=>true,
];
$denied=$service->certify('org-a','paper-master',77,'APPROVE_VERIFIED_INDEPENDENT_EVIDENCE');
$assert($denied['status']==='BLOCKED','No coverage records must block a NAV snapshot.');
$assert($repository->saved===[],'Blocked evidence must never create NAV.');

foreach (['EXTERNAL_FLOWS','LIABILITIES','POSITIONS','VENUES'] as $scope) {
    $source->append('org-a','paper-master',$fact('ACCOUNT_COVERAGE','coverage-'.$scope,'0',[
        ...$cover,'coverage_scope'=>$scope,
    ]));
}
$denied=$service->certify('org-a','paper-master',77,'');
$assert($denied['status']==='BLOCKED','Explicit consent is required.');
$denied=$service->certify('org-a','paper-master',21,'APPROVE_VERIFIED_INDEPENDENT_EVIDENCE');
$assert($denied['status']==='BLOCKED','Importer cannot self-approve.');
$source->records[0]['amount']='101';
$denied=$service->certify('org-a','paper-master',77,'APPROVE_VERIFIED_INDEPENDENT_EVIDENCE');
$assert($denied['status']==='BLOCKED','Venue statement mismatch must block NAV.');
$source->records[0]['amount']='100';
$source->records[1]['currency']='EUR';
$denied=$service->certify('org-a','paper-master',77,'APPROVE_VERIFIED_INDEPENDENT_EVIDENCE');
$assert($denied['status']==='BLOCKED','Nonconverted independent movements must block NAV.');
$source->records[1]['currency']='USD';
$trading->positions=[[
    'position_id'=>'POS-A','portfolio_id'=>'paper-master','instrument_id'=>'AAPLx',
    'venue_id'=>'VENUE-1','instrument_kind'=>'TOKENIZED_EQUITY',
    'quantity'=>'2','side'=>'LONG','status'=>'OPEN',
]];
$denied=$service->certify('org-a','paper-master',77,'APPROVE_VERIFIED_INDEPENDENT_EVIDENCE');
$assert($denied['status']==='BLOCKED','Unconfirmed custody/mark cannot enter portfolio NAV.');
$trading->positions=[];
$trading->ledger[0]['entries'][0]['debit']='105';
$denied=$service->certify('org-a','paper-master',77,'APPROVE_VERIFIED_INDEPENDENT_EVIDENCE');
$assert($denied['status']==='BLOCKED','Unbalanced trading journal must block NAV.');
$trading->ledger[0]['entries'][0]['debit']='100';

$ok=$service->certify('org-a','paper-master',77,'APPROVE_VERIFIED_INDEPENDENT_EVIDENCE');
$assert($ok['status']==='COMPLETE' && $ok['snapshot_written']===true,'Reconciled documentary NAV needs persisted COMPLETE status.');
$assert(count($repository->saved)===1,'One financial acceptance should append one immutable NAV snapshot.');
$assert($ok['snapshot']['equity']==='100','Cash less explicit zero liabilities must be exact.');
$assert($ok['snapshot']['cumulative_external_net_flow']==='100','Certified external flow history must be retained.');
$assert(str_starts_with($ok['snapshot']['provenance_id'],'DUAL_CONTROL:77:'),'Snapshot must identify accountable independent reviewer.');
$windows=PortfolioNavWindowProjector::project($repository->saved);
$assert($windows['today']['net_pnl']===null && $windows['30d']['net_pnl']===null,
    'One NAV boundary never fabricates Today/30D profit.');
echo "Capital Markets independent NAV reconciliation acceptance passed.\n";
