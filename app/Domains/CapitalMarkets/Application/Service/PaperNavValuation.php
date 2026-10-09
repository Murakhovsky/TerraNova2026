<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Throwable;

/**
 * Paper-only valuation policy: portfolio capital book + mark-to-entry changes.
 * Venue balances are a reconciliation cross-check, NOT additional assets.
 * NEVER emits COMPLETE, reconciliation=true or a real-money NAV.
 */
final class PaperNavValuation
{
    /** @param array<string,mixed> $portfolio
     *  @param list<array<string,mixed>> $positions
     *  @param list<array<string,mixed>> $balances
     *  @param array<string,array<string,mixed>> $marks
     *  @return array<string,mixed>
     */
    public static function calculate(
        array $portfolio, array $positions, array $balances, array $marks,
        ?DateTimeImmutable $at = null, string $portfolioId = 'paper-master',
    ): array {
        $now = ($at ?? new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $issues=[];
        $unavailable=static fn(array $why):array=>[
            'status'=>'UNAVAILABLE','mode'=>'PAPER','valuation_status'=>'UNAVAILABLE',
            'equity'=>null,'net_pnl'=>null,'unrealized_pnl'=>null,'currency'=>null,
            'reasons'=>array_values(array_unique($why)),
        ];
        $currency=strtoupper(trim((string)($portfolio['currency']??'')));
        if ($currency==='' || $portfolioId==='' || !in_array($currency,['USD','USDT','USDC','EUR'],true)) {
            return $unavailable(['PAPER_PORTFOLIO_CURRENCY_UNSUPPORTED']);
        }
        try {
            $initial=Decimal::fromString((string)($portfolio['initial_capital']??''));
            $available=Decimal::fromString((string)($portfolio['available_capital']??''));
            $reserved=Decimal::fromString((string)($portfolio['reserved_capital']??''));
            $realized=Decimal::fromString((string)($portfolio['realized_pnl']??''));
            if ($initial->isNegative() || $available->isNegative() || $reserved->isNegative()) {
                $issues[]='PAPER_CAPITAL_NEGATIVE';
            }
            $capital=DecimalMath::add($available,$reserved);
            if (DecimalMath::subtract($capital,DecimalMath::add($initial,$realized))->isZero()===false) {
                $issues[]='PAPER_CAPITAL_BOOK_MISMATCH';
            }
        } catch (Throwable) {
            return $unavailable(['PAPER_CAPITAL_DECIMAL_INVALID']);
        }

        if (count($positions)>=5000) $issues[]='PAPER_POSITION_HISTORY_TRUNCATED';
        $open=[];
        $ownedQty=[];
        $fingerprint=[];
        foreach ($positions as $position) {
            if (!is_array($position)) {$issues[]='PAPER_POSITION_ROW_INVALID';continue;}
            $scope=(string)($position['portfolio_id']??'');
            // VS1/VS2 persist execution subportfolios as paper:<execution_id>.
            if ($scope!==$portfolioId && !str_starts_with($scope,'paper:')) {
                $issues[]='PAPER_POSITION_SCOPE_UNVERIFIED';continue;
            }
            $id=trim((string)($position['position_id']??''));
            if ($id==='' || isset($fingerprint[$id])) {$issues[]='PAPER_POSITION_ID_DUPLICATE';continue;}
            $fingerprint[$id]=$position;
            $status=strtoupper((string)($position['status']??''));
            if ($status==='CLOSED') {
                try {
                    if (!Decimal::fromString((string)($position['quantity']??''))->isZero())
                        $issues[]='PAPER_CLOSED_POSITION_NONZERO';
                } catch (Throwable) {$issues[]='PAPER_CLOSED_POSITION_INVALID';}
                continue;
            }
            if ($status!== 'OPEN'
                || !in_array(strtoupper((string)($position['instrument_kind']??'')),['SPOT','TOKENIZED_EQUITY'],true)
                || strtoupper((string)($position['side']??''))!=='LONG'
                || ($position['contract_multiplier']??null)!==null && (string)$position['contract_multiplier']!=='1') {
                $issues[]='PAPER_DERIVATIVE_SHORT_OR_UNCLASSIFIED_POSITION_UNSUPPORTED';
                continue;
            }
            $venue=trim((string)($position['venue_id']??''));
            $instrument=trim((string)($position['instrument_id']??''));
            if ($venue==='' || $instrument==='') {$issues[]='PAPER_POSITION_INSTRUMENT_MISSING';continue;}
            $open[$id]=$position;
            $inventoryKey=$venue.'|'.strtoupper($instrument);
            try {
                $quantity=Decimal::fromString((string)($position['quantity']??''));
                if (!$quantity->isPositive()) {$issues[]='PAPER_OPEN_QUANTITY_INVALID';continue;}
                $ownedQty[$inventoryKey]=isset($ownedQty[$inventoryKey])
                    ? DecimalMath::add($ownedQty[$inventoryKey],$quantity):$quantity;
            } catch (Throwable) {$issues[]='PAPER_POSITION_QUANTITY_INVALID';}
        }

        $seenBalances=[];
        foreach ($balances as $balance) {
            if (!is_array($balance)) {$issues[]='PAPER_VENUE_BALANCE_INVALID';continue;}
            $venue=trim((string)($balance['venue_id']??''));
            $asset=strtoupper(trim((string)($balance['asset_key']??'')));
            $key=$venue.'|'.$asset;
            if ($venue==='' || $asset==='' || isset($seenBalances[$key])) {
                $issues[]='PAPER_VENUE_BALANCE_DUPLICATE';continue;
            }
            $seenBalances[$key]=true;
            try {
                $avail=Decimal::fromString((string)($balance['available_amount']??''));
                $hold=Decimal::fromString((string)($balance['reserved_amount']??''));
                if ($avail->isNegative() || $hold->isNegative()) {
                    $issues[]='PAPER_VENUE_BALANCE_NEGATIVE';continue;
                }
                if ($asset===$currency) continue; // portfolio capital book is the cash authority
                $total=DecimalMath::add($avail,$hold);
                if (!isset($ownedQty[$key]) || !DecimalMath::subtract($ownedQty[$key],$total)->isZero()) {
                    $issues[]='PAPER_INVENTORY_RECONCILIATION_REQUIRED';
                }
            } catch (Throwable) {$issues[]='PAPER_VENUE_BALANCE_DECIMAL_INVALID';}
        }
        // A position whose inventory is absent must not silently become a
        // claim on equity; historical demos with no venue inventory can only
        // produce UNAVAILABLE until the simulator balances are reconciled.
        foreach ($ownedQty as $key=>$quantity) {
            if (!isset($seenBalances[$key])) $issues[]='PAPER_POSITION_INVENTORY_MISSING';
        }

        $unrealized=Decimal::fromString('0');
        $markProvenance=[];
        foreach ($open as $id=>$position) {
            $mark=$marks[$id]??null;
            if (!is_array($mark) || ($mark['status']??null)!=='CANDIDATE'
                || ($mark['position_id']??null)!==$id
                || strtoupper((string)($mark['quote_currency']??''))!==$currency
                || !is_string($mark['mark_source_fingerprint']??null)
                || preg_match('/^[a-f0-9]{64}$/',$mark['mark_source_fingerprint'])!==1) {
                $issues[]='PAPER_OPEN_POSITION_MARK_UNAVAILABLE';continue;
            }
            try {
                $quantity=Decimal::fromString((string)$position['quantity']);
                $markedQty=Decimal::fromString((string)($mark['quantity']??''));
                $price=Decimal::fromString((string)($mark['mark_mid']??''));
                $entry=Decimal::fromString((string)($position['average_entry_price']??''));
                $markTime=new DateTimeImmutable((string)($mark['source_timestamp']??''));
                $age=$now->getTimestamp()-$markTime->getTimestamp();
                if ($quantity->compareTo($markedQty)!==0 || $age<0 || $age>30
                    || !$price->isPositive() || !$entry->isPositive()) {
                    $issues[]='PAPER_MARK_QUANTITY_AGE_OR_ENTRY_INVALID';continue;
                }
                $unrealized=DecimalMath::add($unrealized,DecimalMath::multiply(
                    $quantity,DecimalMath::subtract($price,$entry),
                ));
                $markProvenance[]=$id.'|'.$mark['mark_source_fingerprint'].'|'.$mark['source_timestamp'];
            } catch (Throwable) {$issues[]='PAPER_MARK_CALCULATION_FAILED';}
        }
        if ($issues!==[])return $unavailable($issues);
        $equity=DecimalMath::add($capital,$unrealized);
        if ($equity->isNegative())return $unavailable(['PAPER_NAV_NEGATIVE_UNSUPPORTED']);
        sort($markProvenance,SORT_STRING);
        return [
            'status'=>'SIMULATED', 'mode'=>'PAPER','valuation_status'=>'SIMULATED',
            'equity'=>$equity->value(),'currency'=>$currency,
            'initial_capital'=>$initial->value(),
            'realized_pnl'=>$realized->value(),
            'unrealized_pnl'=>$unrealized->value(),
            'net_pnl'=>DecimalMath::subtract($equity,$initial)->value(),
            'cumulative_external_net_flow'=>$initial->value(),
            'valued_at'=>$now->format(DATE_ATOM),
            'portfolio_id'=>$portfolioId,
            'position_count'=>count($open),
            'source_fingerprint'=>hash('sha256',json_encode([
                'portfolio'=>$portfolio,'positions'=>$fingerprint,
                'balances'=>$balances,'marks'=>$markProvenance,
            ],JSON_THROW_ON_ERROR)),
            'certified'=>false,'ledger_reconciled'=>false,
            'external_flows_reconciled'=>false,'reasons'=>[],
        ];
    }
}
