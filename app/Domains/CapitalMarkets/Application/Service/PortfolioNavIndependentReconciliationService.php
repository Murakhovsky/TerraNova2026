<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\PortfolioNavFinancialEvidenceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Throwable;

/**
 * Two-person, documentary NAV acceptance for the supported long-only spot
 * paper-portfolio model. The imported primary-source facts, custody quantities,
 * external movement coverage and balanced trading journal must all agree.
 * This is a controlled manual signoff path, NOT a substitute for live external
 * venue statement retrieval or derivative/multi-currency accounting.
 */
final readonly class PortfolioNavIndependentReconciliationService
{
    public function __construct(
        private CapitalMarketsTradingRepositoryInterface $trading,
        private PortfolioNavFinancialEvidenceRepositoryInterface $sources,
        private PortfolioNavEvidenceCollector $collector,
        private PortfolioNavSnapshotProducer $producer,
        private CapitalMarketsAccessControlInterface $access,
    ) {}

    /** @return array<string,mixed> */
    public function certify(
        string $organizationId,
        string $portfolioId,
        int $reviewerId,
        string $approval,
        ?DateTimeImmutable $at = null,
    ): array {
        $issues = [];
        $block = static fn(array $codes): array => [
            'status'=>'BLOCKED', 'snapshot_written'=>false,
            'issues'=>array_values(array_unique($codes)),
        ];
        if (trim($organizationId)==='' || trim($portfolioId)==='' || $reviewerId < 1
            || $approval !== 'APPROVE_VERIFIED_INDEPENDENT_EVIDENCE') {
            return $block(['INDEPENDENT_REVIEWER_AND_EXPLICIT_APPROVAL_REQUIRED']);
        }
        $manage = $this->access->hasCapability($organizationId,$reviewerId,CapitalMarketsCapability::Manage->value);
        if (!$manage && (!$this->access->hasCapability($organizationId,$reviewerId,CapitalMarketsCapability::PortfolioManage->value)
            || !$this->access->hasCapability($organizationId,$reviewerId,CapitalMarketsCapability::RiskManagePolicy->value))) {
            return $block(['INDEPENDENT_FINANCIAL_REVIEW_AUTHORITY_REQUIRED']);
        }

        $utc = new DateTimeZone('UTC');
        $now = ($at ?? new DateTimeImmutable('now',$utc))->setTimezone($utc);
        if ($now > new DateTimeImmutable('now',$utc)) return $block(['FUTURE_NAV_UNSUPPORTED']);
        $portfolio = $this->trading->paperPortfolio($organizationId);
        if (!is_array($portfolio) || strtoupper((string)($portfolio['currency']??''))==='') {
            return $block(['PORTFOLIO_CURRENCY_MISSING']);
        }
        $currency = strtoupper((string)$portfolio['currency']);
        $balances = $this->trading->listPaperBalances($organizationId);
        $positions = $this->trading->listPositions($organizationId,5000);
        $ledger = $this->trading->listLedgerTransactions($organizationId,5000);
        $facts = $this->sources->forPortfolio($organizationId,$portfolioId);
        if (count($ledger)>=5000 || count($positions)>=5000) $issues[]='ACCOUNTING_DATA_TRUNCATED';
        $audit=PortfolioLedgerIntegrityAudit::inspect($ledger);
        if ($audit['status']!=='JOURNAL_BALANCED' || !is_string($audit['journal_fingerprint']??null)) {
            $issues[]='TRADING_JOURNAL_NOT_RECONCILED';
        }

        // All source records must come from genuine document imports. Their raw
        // text is independently reviewed by an authorized second operator.
        $kinds=['VENUE_BALANCE'=>[], 'LIABILITY_BALANCE'=>[], 'EXTERNAL_CASH_FLOW'=>[],
            'POSITION_BALANCE'=>[], 'ACCOUNT_COVERAGE'=>[]];
        $sourcesFingerprint=[];
        $seenKeys=[];
        foreach ($facts as $fact) {
            if (!is_array($fact) || !array_key_exists((string)($fact['kind']??''),$kinds)) {
                $issues[]='INVALID_INDEPENDENT_SOURCE_TYPE';
                continue;
            }
            $kind=(string)$fact['kind'];
            $collectorId=trim((string)($fact['collected_by']??''));
            if ($collectorId==='' || $collectorId===(string)$reviewerId) $issues[]='DUAL_CONTROL_NOT_INDEPENDENT';
            if (($fact['status']??'')!=='PENDING_RECONCILIATION' || ($fact['reconciled']??null)!==false
                || !preg_match('/^[a-f0-9]{64}$/',(string)($fact['source_document_sha256']??''))
                || !preg_match('/^[a-f0-9]{64}$/',(string)($fact['source_key_sha256']??''))) {
                $issues[]='DOCUMENT_PROVENANCE_OR_AUTHORITY_INVALID';
            }
            $key=(string)($fact['source_key_sha256']??'');
            if (isset($seenKeys[$key])) $issues[]='DUPLICATE_SOURCE_REFERENCE';
            $seenKeys[$key]=true;
            if (strtoupper((string)($fact['currency']??''))!==$currency) $issues[]='NAV_SOURCE_CURRENCY_UNCONVERTED';
            $stamp=self::instant($fact['effective_at']??null);
            if ($stamp===null || $stamp>$now) $issues[]='SOURCE_TIMESTAMP_INVALID_OR_FUTURE';
            $kinds[$kind][]=$fact;
            $sourcesFingerprint[]=[
                'id'=>$fact['evidence_id']??null,
                'digest'=>$fact['source_document_sha256']??null,
                'source'=>$key,
            ];
        }
        $coverage=[];
        $inception=self::instant($portfolio['created_at']??null)
            ?? new DateTimeImmutable('1970-01-01T00:00:00+00:00');
        foreach ($kinds['ACCOUNT_COVERAGE'] as $fact) {
            $scope=(string)($fact['coverage_scope']??'');
            if (!in_array($scope,['EXTERNAL_FLOWS','LIABILITIES','POSITIONS','VENUES'],true)
                || isset($coverage[$scope])) {
                $issues[]='COVERAGE_DUPLICATE_OR_INVALID'; continue;
            }
            $from=self::instant($fact['coverage_from']??null);
            $through=self::instant($fact['coverage_through']??null);
            if (!self::recent($fact,$now) || $from===null || $through===null || $from>$inception
                || $through<$now->modify('-900 seconds') || $through>$now
                || ($fact['all_accounts']??false)!==true) {
                $issues[]='ACCOUNT_COVERAGE_INCOMPLETE'; continue;
            }
            $coverage[$scope]=$fact;
        }
        foreach (['EXTERNAL_FLOWS','LIABILITIES','POSITIONS','VENUES'] as $scope) {
            if (!isset($coverage[$scope])) $issues[]='ACCOUNT_COVERAGE_MISSING_'.$scope;
        }

        // Independent venue cash statement must equal available + reserved for
        // every ledger venue. Foreign assets are NOT silently priced as cash.
        $paper=[];
        $paperInventory=[];
        $seenBalanceKeys=[];
        foreach ($balances as $row) {
            if (!is_array($row)) {$issues[]='PAPER_BALANCE_INVALID';continue;}
            $venue=trim((string)($row['venue_id']??''));
            $asset=strtoupper(trim((string)($row['asset_key']??'')));
            $key=$venue.'|'.$asset;
            if ($venue==='' || $asset==='' || isset($seenBalanceKeys[$key])) {
                $issues[]='BALANCE_SCOPE_CURRENCY_OR_DUPLICATE';continue;
            }
            $seenBalanceKeys[$key]=true;
            try {
                $available=Decimal::fromString((string)($row['available_amount']??''));
                $reserved=Decimal::fromString((string)($row['reserved_amount']??''));
                if ($available->isNegative() || $reserved->isNegative()) {
                    $issues[]='PAPER_BALANCE_NEGATIVE';continue;
                }
                $total=DecimalMath::add($available,$reserved);
                if ($asset===$currency) $paper[$venue]=$total;
                else $paperInventory[$key]=$total; // verify against custody and position quantities below
            } catch (Throwable) {$issues[]='PAPER_BALANCE_AMOUNT_INVALID';}
        }
        $statementVenues=[];
        foreach ($kinds['VENUE_BALANCE'] as $fact) {
            $venue=trim((string)($fact['venue_id']??''));
            self::latest($statementVenues,$venue,$fact,$issues,'VENUE_BALANCE');
        }
        $cash=[];
        $venues=array_unique([...array_keys($paper),...array_keys($statementVenues)]);
        if ($venues===[]) $issues[]='VENUE_CASH_STATEMENTS_MISSING';
        sort($venues,SORT_STRING);
        foreach ($venues as $venue) {
            $source=$statementVenues[$venue]['record']??null;
            if (!isset($paper[$venue]) || !is_array($source)) {
                $issues[]='VENUE_CASH_COVERAGE_INCOMPLETE';continue;
            }
            if (!self::recent($source,$now)) $issues[]='VENUE_STATEMENT_STALE';
            try {
                $amount=Decimal::fromString((string)($source['amount']??''));
                if ($amount->isNegative() || !DecimalMath::subtract($amount,$paper[$venue])->isZero()) {
                    $issues[]='VENUE_CASH_MISMATCH';
                }
                $cash[]=['currency'=>$currency,'amount'=>$amount->value(),'venue_id'=>$venue];
            } catch (Throwable) {$issues[]='VENUE_STATEMENT_AMOUNT_INVALID';}
        }

        // Liabilities are account snapshots, never the sum of their history.
        $debtAccounts=[];
        foreach ($kinds['LIABILITY_BALANCE'] as $fact) {
            self::latest($debtAccounts,trim((string)($fact['liability_account_id']??'')),$fact,$issues,'LIABILITY_BALANCE');
        }
        $liabilities=[];
        foreach ($debtAccounts as $account=>$record) {
            $fact=$record['record'];
            if (!self::recent($fact,$now)) $issues[]='LIABILITY_STATEMENT_STALE';
            try {
                $amount=Decimal::fromString((string)($fact['amount']??''));
                if ($amount->isNegative()) $issues[]='LIABILITY_NEGATIVE';
                $liabilities[]=['currency'=>$currency,'amount'=>$amount->value(),'account'=>$account];
            } catch (Throwable) {$issues[]='LIABILITY_AMOUNT_INVALID';}
        }
        // A signed all-accounts coverage record explicitly attests that an
        // empty liability list is an observed zero, not missing source data.

        $flows=Decimal::fromString('0');
        $seenFlowEvents=[];
        foreach ($kinds['EXTERNAL_CASH_FLOW'] as $fact) {
            $provider=trim((string)($fact['provider_id']??''));
            $event=trim((string)($fact['provider_event_id']??''));
            if ($provider==='' || $event==='') {
                $issues[]='EXTERNAL_FLOW_PROVIDER_EVENT_ID_MISSING';
                continue;
            }
            $eventKey=$provider.'|'.$event;
            if (isset($seenFlowEvents[$eventKey])) {
                $issues[]='DUPLICATE_PROVIDER_CASH_FLOW_EVENT';
                continue;
            }
            $seenFlowEvents[$eventKey]=true;
            $when=self::instant($fact['effective_at']??null);
            if ($when===null || $when<$inception || $when>$now) {
                $issues[]='EXTERNAL_FLOW_OUT_OF_COVERAGE';continue;
            }
            try {
                $amount=Decimal::fromString((string)($fact['amount']??''));
                if ($amount->isZero()) $issues[]='EXTERNAL_FLOW_ZERO_EVENT';
                $flows=DecimalMath::add($flows,$amount);
            } catch (Throwable) {$issues[]='EXTERNAL_FLOW_INVALID';}
        }

        // Custody inventory must independently match EACH open supported spot
        // position before a mark candidate can become a reconciled mark.
        $custody=[];
        foreach ($kinds['POSITION_BALANCE'] as $fact) {
            self::latest($custody,trim((string)($fact['position_id']??'')),$fact,$issues,'POSITION_BALANCE');
        }
        $current=[];
        foreach ($positions as $position) {
            if (!is_array($position)) {$issues[]='POSITION_RECORD_INVALID';continue;}
            $owner=(string)($position['portfolio_id']??'');
            if ($owner!==$portfolioId && !str_starts_with($owner,'paper:')) {
                $issues[]='POSITION_PORTFOLIO_SCOPE_UNVERIFIED';continue;
            }
            $id=trim((string)($position['position_id']??''));
            if ($id==='' || isset($current[$id])) {$issues[]='POSITION_IDENTITY_DUPLICATE';continue;}
            $status=strtoupper((string)($position['status']??''));
            if ($status==='CLOSED') {
                try {
                    if (!Decimal::fromString((string)($position['quantity']??''))->isZero()) $issues[]='CLOSED_POSITION_NONZERO';
                } catch (Throwable) {$issues[]='CLOSED_POSITION_QUANTITY_INVALID';}
                continue;
            }
            $current[$id]=$position;
        }
        // Noncash paper balances are inventory quantities, not cash NAV.
        // Cross-check them against open canonical positions and independent
        // custody reports. Position marks are the only valued inventory asset.
        $positionInventory=[];
        foreach ($current as $position) {
            $inventoryKey=trim((string)($position['venue_id']??'')).'|'
                .strtoupper(trim((string)($position['instrument_id']??'')));
            try {
                $qty=Decimal::fromString((string)($position['quantity']??''));
                $positionInventory[$inventoryKey]=isset($positionInventory[$inventoryKey])
                    ? DecimalMath::add($positionInventory[$inventoryKey],$qty)
                    : $qty;
            } catch (Throwable) {$issues[]='POSITION_QUANTITY_INVALID';}
        }
        foreach ($paperInventory as $inventoryKey=>$balance) {
            if (!isset($positionInventory[$inventoryKey])
                || !DecimalMath::subtract($balance,$positionInventory[$inventoryKey])->isZero()) {
                $issues[]='NONCASH_INVENTORY_WITHOUT_MATCHING_POSITION';
            }
        }
        foreach ($custody as $id=>$record) if (!isset($current[$id])) $issues[]='UNMATCHED_CUSTODY_POSITION';
        $marks=[];
        // Collector independently resolves existing canonical MarketState source
        // timestamps and rejects perpetual/short/FX or stale spot positions.
        $preflight=$this->collector->inspect($organizationId,$portfolioId);
        $candidates=[];
        foreach ($preflight['marks']??[] as $candidate) {
            if (is_array($candidate)) $candidates[(string)($candidate['position_id']??'')]=$candidate;
        }
        foreach ($current as $id=>$position) {
            $record=$custody[$id]['record']??null;
            $candidate=$candidates[$id]??null;
            if (!is_array($record) || !is_array($candidate)) {
                $issues[]='POSITION_INDEPENDENT_CUSTODY_OR_MARK_MISSING';continue;
            }
            if (!self::recent($record,$now)
                || (string)($record['instrument_id']??'')!==(string)($position['instrument_id']??'')
                || (string)($record['venue_id']??'')!==(string)($position['venue_id']??'')
                || (string)($candidate['instrument_id']??'')!==(string)($position['instrument_id']??'')
                || (string)($candidate['venue_id']??'')!==(string)($position['venue_id']??'')) {
                $issues[]='CUSTODY_POSITION_IDENTITY_OR_STALENESS';continue;
            }
            try {
                $sourceQuantity=Decimal::fromString((string)($record['amount']??''));
                $ledgerQuantity=Decimal::fromString((string)($position['quantity']??''));
                if (!$ledgerQuantity->isPositive()
                    || !DecimalMath::subtract($sourceQuantity,$ledgerQuantity)->isZero()) {
                    $issues[]='CUSTODY_POSITION_QUANTITY_MISMATCH';continue;
                }
                $marks[]=[
                    'position_id'=>$id,'quote_currency'=>$currency,
                    'market_value'=>(string)$candidate['candidate_market_value'],
                    'mark_reconciled'=>true,
                    'mark_source_fingerprint'=>(string)$candidate['mark_source_fingerprint'],
                    'source_timestamp'=>(string)$candidate['source_timestamp'],
                    'market_state_version'=>(int)$candidate['market_state_version'],
                ];
            } catch (Throwable) {$issues[]='POSITION_QUANTITY_INVALID';}
        }
        // Preflight's unresolved-accounting reminders are expected because
        // the separate second-person review happens HERE, never in collector.
        $expected=[
            'POSITION_AND_VENUE_RECONCILIATION_REQUIRED',
            'BALANCE_FX_OR_ASSET_RECONCILIATION_REQUIRED',
            'NONCASH_ASSET_VALUATION_REQUIRED',
            'EXTERNAL_FLOW_RECONCILIATION_PENDING','EXTERNAL_FLOW_LEDGER_UNAVAILABLE',
            'LIABILITY_RECONCILIATION_PENDING','LIABILITY_LEDGER_UNAVAILABLE',
            'LIABILITY_STATEMENT_MISSING','EXTERNAL_FLOW_HISTORY_MISSING',
            'VENUE_BALANCE_RECONCILIATION_PENDING',
        ];
        foreach ($preflight['issues']??[] as $issue) {
            if (!in_array($issue,$expected,true)) $issues[]=(string)$issue;
        }
        if ($issues!==[]) return $block($issues);
        usort($sourcesFingerprint,static fn(array $a,array $b):int =>
            strcmp((string)$a['id'],(string)$b['id']));
        $sourceDigest=hash('sha256',json_encode($sourcesFingerprint,JSON_THROW_ON_ERROR));
        $provenance=hash('sha256',implode('|',[
            $organizationId,$portfolioId,(string)$reviewerId,
            (string)$audit['journal_fingerprint'],$sourceDigest,$now->format(DATE_ATOM),
        ]));
        $evidence=[
            'snapshot_id'=>'nav-certified-'.$provenance,
            'valued_at'=>$now->format(DATE_ATOM),
            'currency'=>$currency,
            'provenance_id'=>'DUAL_CONTROL:'.$reviewerId.':'.$sourceDigest.':'.(string)$audit['journal_fingerprint'],
            'ledger_reconciled'=>true,
            'marks_reconciled'=>true,
            'external_flows_reconciled'=>true,
            'cash_by_currency'=>$cash,
            'liabilities_by_currency'=>$liabilities,
            'external_flows_by_currency'=>[['currency'=>$currency,'amount'=>$flows->value()]],
            'marked_positions'=>$marks,
        ];
        $evidence['ledger_fingerprint']=hash('sha256',json_encode([
            'cash'=>$cash,'liabilities'=>$liabilities,
        ],JSON_THROW_ON_ERROR));
        $evidence['marks_fingerprint']=hash('sha256',json_encode($marks,JSON_THROW_ON_ERROR));
        $evidence['external_flows_fingerprint']=hash('sha256',json_encode(
            $evidence['external_flows_by_currency'],JSON_THROW_ON_ERROR
        ));
        try {
            $snapshot=$this->producer->record($organizationId,$portfolioId,$evidence);
        } catch (Throwable) {
            return $block(['NAV_SNAPSHOT_REJECTED_OR_DUPLICATE']);
        }
        return [
            'status'=>'COMPLETE','snapshot_written'=>true,'snapshot'=>$snapshot,
            'reconciled_venues'=>count($venues),
            'reconciled_positions'=>count($marks),
            'primary_source_count'=>count($facts),
            'reviewer_id'=>$reviewerId,'primary_source_fingerprint'=>$sourceDigest,
        ];
    }

    /** @param array<string,array{timestamp:DateTimeImmutable,record:array<string,mixed>}> $latest
     *  @param array<string,mixed> $fact @param list<string> $issues
     */
    private static function latest(array &$latest,string $id,array $fact,array &$issues,string $kind):void
    {
        $at=self::instant($fact['effective_at']??null);
        if ($id==='' || $at===null) {$issues[]=$kind.'_KEY_OR_TIMESTAMP_INVALID';return;}
        if (!isset($latest[$id]) || $at>$latest[$id]['timestamp']) {
            $latest[$id]=['timestamp'=>$at,'record'=>$fact];return;
        }
        if ($at==$latest[$id]['timestamp']) $issues[]=$kind.'_CONFLICTING_SIMULTANEOUS_STATEMENTS';
    }

    /** @param array<string,mixed> $fact */
    private static function recent(array $fact,DateTimeImmutable $now):bool
    {
        $at=self::instant($fact['effective_at']??null);
        return $at!==null && $at<=$now
            && $now->getTimestamp()-$at->getTimestamp()<=900;
    }

    private static function instant(mixed $value):?DateTimeImmutable
    {
        if (!is_string($value) || preg_match(
            '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/',
            $value
        )!==1) return null;
        try {return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));}
        catch (Throwable) {return null;}
    }
}
