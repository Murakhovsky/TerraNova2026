<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\PortfolioNavFinancialEvidenceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Throwable;

/**
 * Collects tenant-scoped portfolio source evidence without asserting accounting
 * reconciliation. Missing external flows, liabilities or a trusted mark are hard
 * blockers, never zeros. This collector cannot produce a COMPLETE snapshot.
 */
final readonly class PortfolioNavEvidenceCollector
{
    public function __construct(
        private CapitalMarketsTradingRepositoryInterface $trading,
        private MarketStateRepositoryInterface $marketStates,
        private PortfolioNavFinancialEvidenceRepositoryInterface $financialEvidence,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(string $organizationId, string $portfolioId='paper-master'): array
    {
        $issues = [];
        $portfolio = $this->trading->paperPortfolio($organizationId);
        if (!is_array($portfolio) || trim((string)($portfolio['currency'] ?? '')) === '') {
            $issues[] = 'PORTFOLIO_CURRENCY_MISSING';
        }
        $currency = strtoupper((string)($portfolio['currency'] ?? ''));
        $balances = $this->trading->listPaperBalances($organizationId);
        $positions = $this->trading->listPositions($organizationId, 5000);
        $ledger = $this->trading->listLedgerTransactions($organizationId, 5000);
        $ledgerAudit = PortfolioLedgerIntegrityAudit::inspect($ledger);
        foreach ($ledgerAudit['issues'] as $issue) $issues[] = $issue;
        $marks = [];
        $scopedPositionCount = 0;
        if (count($positions) >= 5000) $issues[] = 'POSITIONS_PAGE_TRUNCATED';
        if (count($ledger) >= 5000) $issues[] = 'LEDGER_PAGE_TRUNCATED';

        foreach ($positions as $position) {
            if (!is_array($position)) continue;
            $assignedPortfolio = (string)($position['portfolio_id'] ?? '');
            if ($assignedPortfolio !== $portfolioId) {
                // Unassigned or legacy positions cannot be silently excluded from NAV.
                if ($assignedPortfolio === '' || str_contains($assignedPortfolio, 'paper:')) {
                    $issues[] = 'POSITION_PORTFOLIO_SCOPE_UNVERIFIED';
                }
                continue;
            }
            $scopedPositionCount++;
            if (strtoupper((string)($position['status'] ?? '')) === 'CLOSED') {
                try {
                    if (!Decimal::fromString((string)($position['quantity'] ?? '0'))->isZero()) {
                        $issues[] = 'CLOSED_POSITION_NONZERO_QUANTITY';
                    }
                } catch (Throwable) {
                    $issues[] = 'CLOSED_POSITION_QUANTITY_INVALID';
                }
                continue;
            }
            $instrument = (string)($position['instrument_id'] ?? '');
            $venue = (string)($position['venue_id'] ?? '');
            $id = (string)($position['position_id'] ?? '');
            if ($instrument === '' || $venue === '' || $id === '') {
                $issues[] = 'POSITION_IDENTITY_MISSING';
                continue;
            }
            try {
                $market = $this->marketStates->get(
                    $organizationId, VenueId::fromString($venue), InstrumentId::fromString($instrument),
                );
                if ($market === null) {
                    $issues[] = 'POSITION_MARK_UNTRUSTED';
                    continue;
                }
                $candidate = PortfolioNavSpotMarkEvidence::inspect(
                    $position, $market->toArray(), $currency,
                );
                if ($candidate['status'] !== 'CANDIDATE') {
                    $reason = (string)$candidate['reason'];
                    // Expose one explicit NAV blocker for stale timestamps or an
                    // uncertain clock, regardless of lower-level market flags.
                    if ($reason === 'MARK_STALE_OR_FUTURE'
                        || in_array('CLOCK_UNCERTAIN', $market->toArray()['quality_flags'] ?? [], true)) {
                        $issues[] = 'POSITION_MARK_STALE_OR_CLOCK_UNCERTAIN';
                    }
                    $issues[] = $reason;
                    continue;
                }
                $marks[] = $candidate;
                // Candidate values are diagnostics only, never approved assets.
                $issues[] = 'POSITION_AND_VENUE_RECONCILIATION_REQUIRED';
            } catch (Throwable) {
                $issues[] = 'POSITION_MARK_LOOKUP_FAILED';
            }
        }
        $balanceKeys = [];
        if ($balances === []) $issues[] = 'VENUE_BALANCES_MISSING';
        foreach ($balances as $balance) {
            if (!is_array($balance)) {
                $issues[] = 'BALANCE_ROW_INVALID';
                continue;
            }
            $asset = strtoupper(trim((string)($balance['asset_key'] ?? '')));
            $venue = (string)($balance['venue_id'] ?? '');
            if ($venue === '' || $asset === '') {
                $issues[] = 'BALANCE_IDENTITY_MISSING';
                continue;
            }
            $key = $venue.'|'.$asset;
            if (isset($balanceKeys[$key])) $issues[] = 'BALANCE_DUPLICATE';
            $balanceKeys[$key] = true;
            if ($asset !== $currency) $issues[] = 'BALANCE_FX_OR_ASSET_RECONCILIATION_REQUIRED';
            try {
                $available = Decimal::fromString((string)($balance['available_amount'] ?? ''));
                $reserved = Decimal::fromString((string)($balance['reserved_amount'] ?? ''));
                if ($available->isNegative() || $reserved->isNegative()) {
                    $issues[] = 'BALANCE_NEGATIVE_AMOUNT';
                }
            } catch (Throwable) {
                $issues[] = 'BALANCE_AMOUNT_INVALID';
            }
        }
        // Source documents are observations, never automatically certified ledger facts.
        $sourceEvidence = $this->financialEvidence->forPortfolio($organizationId, $portfolioId);
        $sourceCounts = [
            'EXTERNAL_CASH_FLOW' => 0,
            'LIABILITY_BALANCE' => 0,
            'VENUE_BALANCE' => 0,
        ];
        foreach ($sourceEvidence as $observation) {
            if (!is_array($observation) || !array_key_exists((string)($observation['kind'] ?? ''), $sourceCounts)) {
                $issues[] = 'NAV_SOURCE_EVIDENCE_INVALID';
                continue;
            }
            $sourceCounts[(string)$observation['kind']]++;
            if (($observation['status'] ?? '') !== 'PENDING_RECONCILIATION'
                || ($observation['reconciled'] ?? null) !== false) {
                $issues[] = 'NAV_SOURCE_AUTHORITY_UNEXPECTED';
            }
        }
        $issues[] = $sourceCounts['EXTERNAL_CASH_FLOW'] === 0
            ? 'EXTERNAL_FLOW_LEDGER_UNAVAILABLE'
            : 'EXTERNAL_FLOW_RECONCILIATION_PENDING';
        $issues[] = $sourceCounts['LIABILITY_BALANCE'] === 0
            ? 'LIABILITY_LEDGER_UNAVAILABLE'
            : 'LIABILITY_RECONCILIATION_PENDING';
        $issues[] = $sourceCounts['VENUE_BALANCE'] === 0
            ? 'VENUE_BALANCE_RECONCILIATION_UNAVAILABLE'
            : 'VENUE_BALANCE_RECONCILIATION_PENDING';
        $issues = array_values(array_unique($issues));
        return [
            'status'=>'BLOCKED',
            'organization_id'=>$organizationId,
            'portfolio_id'=>$portfolioId,
            'currency'=>$currency !== '' ? $currency : null,
            'sources'=>[
                'positions_examined'=>count($positions),
                'positions_in_scope'=>$scopedPositionCount,
                'venue_balances_examined'=>count($balances),
                'ledger_rows_examined'=>count($ledger),
                'market_marks_examined'=>count($marks),
            ],
            'marks'=>$marks,
            'trading_ledger_audit'=>$ledgerAudit,
            'unreconciled_financial_evidence'=>$sourceCounts,
            'issues'=>$issues,
            'snapshot_written'=>false,
        ];
    }
}
