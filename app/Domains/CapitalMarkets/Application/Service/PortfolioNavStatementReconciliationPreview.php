<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Throwable;

/**
 * Deterministic preview of accounting-source differences. Source documents are
 * NOT certified by matching an internal projection against one other source.
 * Approval requires independent statements + ledger reconciliation authority.
 */
final class PortfolioNavStatementReconciliationPreview
{
    /**
     * @param list<array<string,mixed>> $paperBalances
     * @param list<array<string,mixed>> $sourceEvidence
     * @return array<string,mixed>
     */
    public static function inspect(array $paperBalances, array $sourceEvidence, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        $issues = [];
        $paper = [];
        $statements = [];
        $externalFlows = Decimal::fromString('0');
        $liabilities = Decimal::fromString('0');
        $counts = ['EXTERNAL_CASH_FLOW'=>0,'LIABILITY_BALANCE'=>0,'VENUE_BALANCE'=>0];

        if ($currency === '') $issues['PORTFOLIO_CURRENCY_MISSING'] = true;
        foreach ($paperBalances as $balance) {
            if (!is_array($balance)) {
                $issues['PAPER_BALANCE_ROW_INVALID'] = true;
                continue;
            }
            $venue = trim((string)($balance['venue_id'] ?? ''));
            $unit = strtoupper(trim((string)($balance['asset_key'] ?? '')));
            if ($venue === '' || $unit === '') {
                $issues['PAPER_BALANCE_IDENTITY_MISSING'] = true;
                continue;
            }
            if ($unit !== $currency) {
                $issues['NONCASH_ASSET_VALUATION_REQUIRED'] = true;
                continue;
            }
            if (isset($paper[$venue])) {
                $issues['PAPER_CASH_BALANCE_DUPLICATE'] = true;
                continue;
            }
            try {
                $available = Decimal::fromString((string)($balance['available_amount'] ?? ''));
                $reserved = Decimal::fromString((string)($balance['reserved_amount'] ?? ''));
                if ($available->isNegative() || $reserved->isNegative()) {
                    $issues['PAPER_CASH_BALANCE_NEGATIVE'] = true;
                    continue;
                }
                $paper[$venue] = DecimalMath::add($available, $reserved);
            } catch (Throwable) {
                $issues['PAPER_CASH_BALANCE_INVALID'] = true;
            }
        }
        $seenSources = [];
        foreach ($sourceEvidence as $evidence) {
            if (!is_array($evidence)) {
                $issues['SOURCE_EVIDENCE_ROW_INVALID'] = true;
                continue;
            }
            $kind = (string)($evidence['kind'] ?? '');
            if (!array_key_exists($kind, $counts)) {
                $issues['SOURCE_EVIDENCE_KIND_INVALID'] = true;
                continue;
            }
            $counts[$kind]++;
            if (($evidence['status'] ?? '') !== 'PENDING_RECONCILIATION'
                || ($evidence['reconciled'] ?? null) !== false) {
                $issues['SOURCE_AUTHORITY_UNEXPECTED'] = true;
                continue;
            }
            $fingerprint = strtolower(trim((string)($evidence['source_document_sha256'] ?? '')));
            $sourceKey = trim((string)($evidence['source_key_sha256'] ?? ''));
            if (!preg_match('/^[0-9a-f]{64}$/', $fingerprint)
                || !preg_match('/^[0-9a-f]{64}$/', $sourceKey)) {
                $issues['SOURCE_PROVENANCE_INCOMPLETE'] = true;
                continue;
            }
            if (isset($seenSources[$sourceKey])) {
                $issues['DUPLICATE_SOURCE_REFERENCE'] = true;
                continue;
            }
            $seenSources[$sourceKey] = true;
            if (strtoupper((string)($evidence['currency'] ?? '')) !== $currency) {
                $issues['SOURCE_CURRENCY_UNCONVERTED'] = true;
                continue;
            }
            try {
                $amount = Decimal::fromString((string)($evidence['amount'] ?? ''));
                if ($kind !== 'EXTERNAL_CASH_FLOW' && $amount->isNegative()) {
                    $issues['SOURCE_BALANCE_NEGATIVE'] = true;
                    continue;
                }
                if ($kind === 'EXTERNAL_CASH_FLOW') {
                    $externalFlows = DecimalMath::add($externalFlows, $amount);
                } elseif ($kind === 'LIABILITY_BALANCE') {
                    $liabilities = DecimalMath::add($liabilities, $amount);
                } else {
                    $venue = trim((string)($evidence['venue_id'] ?? ''));
                    if ($venue === '') {
                        $issues['SOURCE_VENUE_IDENTITY_MISSING'] = true;
                        continue;
                    }
                    if (isset($statements[$venue])) {
                        $issues['DUPLICATE_VENUE_STATEMENT'] = true;
                        continue;
                    }
                    $statements[$venue] = $amount;
                }
            } catch (Throwable) {
                $issues['SOURCE_AMOUNT_INVALID'] = true;
            }
        }

        $venues = array_unique([...array_keys($paper), ...array_keys($statements)]);
        sort($venues, SORT_STRING);
        $venueComparison = [];
        foreach ($venues as $venue) {
            $internal = $paper[$venue] ?? null;
            $source = $statements[$venue] ?? null;
            if ($internal === null || $source === null) {
                $issues['VENUE_STATEMENT_COVERAGE_INCOMPLETE'] = true;
            }
            $delta = $internal !== null && $source !== null
                ? DecimalMath::subtract($source, $internal) : null;
            if ($delta !== null && !$delta->isZero()) $issues['VENUE_CASH_MISMATCH'] = true;
            $venueComparison[] = [
                'venue_id'=>$venue,
                'paper_cash'=>$internal?->value(),
                'statement_cash'=>$source?->value(),
                'difference'=>$delta?->value(),
                'same_amount'=>$delta?->isZero() ?? false,
                'status'=>'UNRECONCILED_SOURCE_COMPARISON',
            ];
        }
        if ($counts['VENUE_BALANCE'] === 0) $issues['VENUE_STATEMENTS_MISSING'] = true;
        if ($counts['EXTERNAL_CASH_FLOW'] === 0) $issues['EXTERNAL_FLOW_HISTORY_MISSING'] = true;
        if ($counts['LIABILITY_BALANCE'] === 0) $issues['LIABILITY_STATEMENT_MISSING'] = true;

        return [
            'status'=>'PENDING_RECONCILIATION',
            'currency'=>$currency === '' ? null : $currency,
            'candidate_cumulative_external_flow'=>$counts['EXTERNAL_CASH_FLOW'] > 0
                ? $externalFlows->value() : null,
            'candidate_liability_balance'=>$counts['LIABILITY_BALANCE'] > 0
                ? $liabilities->value() : null,
            'venue_comparison'=>$venueComparison,
            'source_counts'=>$counts,
            'issues'=>array_keys($issues),
            'eligible_for_nav'=>false,
            'financial_authority'=>'OBSERVATION_ONLY',
        ];
    }
}
