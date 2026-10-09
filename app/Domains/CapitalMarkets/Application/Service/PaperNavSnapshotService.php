<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\PaperNavSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Throwable;

/**
 * Scheduled ingestion of a paper-only mark-to-entry NAV.
 * This never calls certified NAV producers or financial approval gates.
 */
final readonly class PaperNavSnapshotService
{
    public function __construct(
        private CapitalMarketsTradingRepositoryInterface $trading,
        private MarketStateRepositoryInterface $marketStates,
        private PaperNavSnapshotRepositoryInterface $snapshots,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(string $organizationId,string $portfolioId='paper-master'):array
    {
        $block=static fn(string $reason):array=>[
            'status'=>'UNAVAILABLE','mode'=>'PAPER','snapshot_written'=>false,'reasons'=>[$reason],
        ];
        if (trim($organizationId)==='' || trim($portfolioId)==='') return $block('PAPER_TENANT_PORTFOLIO_REQUIRED');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        try {
            $portfolio=$this->trading->paperPortfolio($organizationId);
            if (!is_array($portfolio)) return $block('PAPER_PORTFOLIO_NOT_INITIALIZED');
            $positions=$this->trading->listPositions($organizationId,5000);
            if (count($positions)>=5000) return $block('PAPER_POSITIONS_TRUNCATED');
            $balances=$this->trading->listPaperBalances($organizationId);
            if (count($balances)>=5000) return $block('PAPER_BALANCES_TRUNCATED');
            $executions=$this->trading->listExecutions($organizationId,5000);
            $ledger=$this->trading->listLedgerTransactions($organizationId,5000);
            if (count($ledger)>=5000 || count($executions)>=5000) return $block('PAPER_LEDGER_OR_EXECUTIONS_TRUNCATED');
            if ($executions!==[] && $ledger===[]) return $block('PAPER_EXECUTIONS_WITHOUT_LEDGER');
            if ($ledger!==[]) {
                $audit=PortfolioLedgerIntegrityAudit::inspect($ledger);
                if ($audit['status']!=='JOURNAL_BALANCED') return $block('PAPER_TRADING_LEDGER_UNBALANCED');
            }
            $marks=[];
            foreach ($positions as $position) {
                if (!is_array($position) || strtoupper((string)($position['status']??''))==='CLOSED') continue;
                $id=trim((string)($position['position_id']??''));
                $venue=trim((string)($position['venue_id']??''));
                $instrument=trim((string)($position['instrument_id']??''));
                if ($id==='' || $venue==='' || $instrument==='') continue;
                try {
                    $state=$this->marketStates->get(
                        $organizationId,VenueId::fromString($venue),InstrumentId::fromString($instrument),
                    );
                    if ($state===null) continue;
                    $marks[$id]=PortfolioNavSpotMarkEvidence::inspect(
                        $position,$state->toArray(),strtoupper((string)($portfolio['currency']??'')),$now,
                    );
                } catch (Throwable) {
                    // Missing mark is a hard valuation gap. The pure engine will
                    // refuse the entire snapshot instead of trusting stored P&L.
                }
            }
            $result=PaperNavValuation::calculate($portfolio,$positions,$balances,$marks,$now,$portfolioId);
            if ($result['status']!=='SIMULATED') return [...$result,'snapshot_written'=>false];
            $result['snapshot_id']='paper-nav-'.hash('sha256',implode('|',[
                $organizationId,$portfolioId,$now->format('Y-m-d\TH:i:s\Z'),
            ]));
            $this->snapshots->append($organizationId,$portfolioId,$result);
            return [...$result,'snapshot_written'=>true];
        } catch (Throwable) {
            return $block('PAPER_NAV_SNAPSHOT_SOURCE_OR_STORAGE_FAILED');
        }
    }
}
