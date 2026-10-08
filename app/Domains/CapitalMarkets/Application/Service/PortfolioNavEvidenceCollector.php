<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
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
                if ($market === null || $market->bestQuote === null
                    || $market->mode->value !== 'LIVE' || $market->marketStatus->value !== 'OPEN'
                    || !$market->quality->status->isUsableForDecision()) {
                    $issues[] = 'POSITION_MARK_UNTRUSTED';
                    continue;
                }
                $quoteAsset = $market->bestQuote->bidPrice->quoteAsset->value();
                if ($quoteAsset !== $currency) {
                    $issues[] = 'POSITION_FX_CONVERSION_REQUIRED';
                    continue;
                }
                $marks[] = [
                    'position_id'=>$id,
                    'instrument_id'=>$instrument,
                    'venue_id'=>$venue,
                    'quote_currency'=>$quoteAsset,
                    'source_timestamp'=>$market->sourceTimestamp->format(DATE_ATOM),
                    'market_state_version'=>$market->stateVersion,
                    'mark_source_fingerprint'=>$market->lastEventFingerprint,
                    'mark_mid'=>$market->bestQuote->midPrice()->value(),
                    // Value and accounting treatment require reconciled positions.
                    'mark_reconciled'=>false,
                ];
            } catch (Throwable) {
                $issues[] = 'POSITION_MARK_LOOKUP_FAILED';
            }
        }
        foreach ($balances as $balance) {
            if (!is_array($balance) || strtoupper((string)($balance['asset_key'] ?? '')) !== $currency) {
                $issues[] = 'BALANCE_FX_OR_ASSET_RECONCILIATION_REQUIRED';
            }
        }
        // The paper trading repository does not yet provide certified
        // external deposit/withdrawal, liability and venue-cash reconciliation.
        $issues[] = 'EXTERNAL_FLOW_LEDGER_UNAVAILABLE';
        $issues[] = 'LIABILITY_LEDGER_UNAVAILABLE';
        $issues[] = 'VENUE_BALANCE_RECONCILIATION_UNAVAILABLE';
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
            'issues'=>$issues,
            'snapshot_written'=>false,
        ];
    }
}
