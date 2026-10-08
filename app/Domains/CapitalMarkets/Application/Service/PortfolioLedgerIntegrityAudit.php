<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Throwable;

/**
 * Integrity check of existing tenant-scoped double-entry trading journal.
 * A balanced trading journal is NOT evidence of external deposits, liabilities,
 * venue cash reconciliation or a valid portfolio NAV.
 */
final class PortfolioLedgerIntegrityAudit
{
    /** @param list<array<string,mixed>> $transactions @return array<string,mixed> */
    public static function inspect(array $transactions): array
    {
        $issues=[];
        $ids=[];
        $fingerprints=[];
        $checked=0;
        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                $issues['INVALID_TRANSACTION_ROW']=true;
                continue;
            }
            $id=(string)($transaction['transaction_id'] ?? $transaction['id'] ?? '');
            $postedAt=(string)($transaction['posted_at'] ?? '');
            $entries=$transaction['entries'] ?? null;
            if ($id==='' || isset($ids[$id]) || $postedAt==='' || !is_array($entries) || count($entries)<2) {
                $issues['LEDGER_TRANSACTION_IDENTITY_OR_ENTRIES_INVALID']=true;
                continue;
            }
            try {
                new DateTimeImmutable($postedAt);
            } catch (Throwable) {
                $issues['LEDGER_TIMESTAMP_INVALID']=true;
                continue;
            }
            $ids[$id]=true;
            $assetTotals=[];
            $normalized=[];
            $bad=false;
            foreach ($entries as $entry) {
                if (!is_array($entry)) {$bad=true; break;}
                $asset=trim((string)($entry['asset_key']??''));
                $account=trim((string)($entry['account']??''));
                $debit=$entry['debit']??null;
                $credit=$entry['credit']??null;
                if ($asset==='' || $account==='' || !is_string($debit) || !is_string($credit)) {
                    $bad=true; break;
                }
                try {
                    $d=Decimal::fromString($debit);
                    $c=Decimal::fromString($credit);
                    if ($d->isNegative()||$c->isNegative()||($d->isPositive()&&$c->isPositive())) {
                        $bad=true; break;
                    }
                    if (!isset($assetTotals[$asset])) $assetTotals[$asset]=Decimal::fromString('0');
                    $assetTotals[$asset]=DecimalMath::add($assetTotals[$asset],DecimalMath::subtract($d,$c));
                    $normalized[]=['account'=>$account,'asset'=>$asset,'debit'=>$d->value(),'credit'=>$c->value()];
                } catch (Throwable) {
                    $bad=true; break;
                }
            }
            if ($bad) {
                $issues['LEDGER_ENTRY_INVALID']=true;
                continue;
            }
            foreach ($assetTotals as $asset=>$difference) {
                if (!$difference->isZero()) {
                    $issues['LEDGER_ASSET_IMBALANCE']=true;
                    $bad=true;
                }
            }
            if ($bad) continue;
            $checked++;
            $fingerprints[$id]=hash('sha256',json_encode([
                'id'=>$id,'posted_at'=>$postedAt,'entries'=>$normalized,
            ],JSON_THROW_ON_ERROR));
        }
        if (count($transactions)>=5000) $issues['LEDGER_PAGE_TRUNCATED']=true;
        if ($checked===0) $issues['NO_AUDITABLE_TRADING_LEDGER']=true;
        ksort($fingerprints,SORT_STRING);
        return [
            'status'=>$issues===[]?'JOURNAL_BALANCED':'INCOMPLETE',
            'transactions_checked'=>$checked,
            'transactions_seen'=>count($transactions),
            'issues'=>array_keys($issues),
            'journal_fingerprint'=>$issues===[]?hash('sha256',json_encode($fingerprints,JSON_THROW_ON_ERROR)):null,
            'external_flows_reconciled'=>false,
            'liabilities_reconciled'=>false,
            'venue_balances_reconciled'=>false,
        ];
    }
}
