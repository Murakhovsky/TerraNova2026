<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Throwable;

/**
 * Portfolio NAV windows, including realized and unrealized economic results.
 * Inputs MUST be reconciled, authoritative valuation snapshots, not
 * cash-reservation totals or execution P&L substituted as NAV.
 */
final class PortfolioNavWindowProjector
{
    /**
     * @param list<array<string,mixed>> $snapshots
     * @return array<string,array<string,mixed>>
     */
    public static function project(array $snapshots, ?DateTimeImmutable $at = null, int $maxSkewSeconds = 900): array
    {
        $utc = new DateTimeZone('UTC');
        $at = ($at ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $windows = [
            'today' => new DateTimeImmutable($at->format('Y-m-d').' 00:00:00', $utc),
            '30d' => $at->modify('-30 days'),
        ];
        $valid = [];
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot)
                || ($snapshot['valuation_status'] ?? '') !== 'COMPLETE'
                || ($snapshot['ledger_reconciled'] ?? false) !== true
                || ($snapshot['marks_reconciled'] ?? false) !== true
                || ($snapshot['external_flows_reconciled'] ?? false) !== true
                || !self::verifiedSnapshotEvidence($snapshot)
                || !is_string($snapshot['equity'] ?? null)
                || !is_string($snapshot['cumulative_external_net_flow'] ?? null)
                || !is_string($snapshot['currency'] ?? null)
                || !is_string($snapshot['valued_at'] ?? null)
            ) continue;
            try {
                $timestamp = (new DateTimeImmutable($snapshot['valued_at'], $utc))->setTimezone($utc);
                $equity = Decimal::fromString($snapshot['equity']);
                $flow = Decimal::fromString($snapshot['cumulative_external_net_flow']);
                $currency = strtoupper(trim($snapshot['currency']));
                if ($currency === '' || $timestamp > $at || $equity->isNegative()) continue;
                $valid[] = [
                    'timestamp' => $timestamp,
                    'equity' => $equity,
                    'cumulative_flow' => $flow,
                    'currency' => $currency,
                ];
            } catch (Throwable) {
                continue;
            }
        }
        usort($valid, static fn(array $a,array $b):int => $a['timestamp'] <=> $b['timestamp']);

        $out = [];
        foreach ($windows as $name => $start) {
            $base = [
                'status' => 'UNAVAILABLE',
                'net_pnl' => null,
                'currency' => null,
                'reason' => 'MISSING_RECONCILED_VALUATIONS',
                'from_utc' => $start->format(DATE_ATOM),
                'to_utc' => $at->format(DATE_ATOM),
                'scope' => 'PORTFOLIO_NAV_REALIZED_AND_UNREALIZED',
            ];
            $opening = null;
            $closing = null;
            foreach ($valid as $row) {
                if ($row['timestamp'] <= $start) $opening = $row;
                if ($row['timestamp'] <= $at) $closing = $row;
            }
            if ($opening === null || $closing === null) {
                $out[$name] = $base;
                continue;
            }
            $openingLag = $start->getTimestamp() - $opening['timestamp']->getTimestamp();
            $closingLag = $at->getTimestamp() - $closing['timestamp']->getTimestamp();
            if ($openingLag > $maxSkewSeconds || $closingLag > $maxSkewSeconds) {
                $out[$name] = [...$base, 'reason'=>'VALUATION_BOUNDARY_STALE'];
                continue;
            }
            if ($opening['currency'] !== $closing['currency']) {
                $out[$name] = [...$base, 'reason'=>'NAV_CURRENCY_MISMATCH'];
                continue;
            }
            $seenTimestamps = [];
            $duplicateValuation = false;
            $intermediateCurrencyMismatch = false;
            foreach ($valid as $observation) {
                if ($observation['timestamp'] < $opening['timestamp']
                    || $observation['timestamp'] > $closing['timestamp']) continue;
                $stamp = $observation['timestamp']->format('Y-m-d H:i:s.u');
                if (isset($seenTimestamps[$stamp])) $duplicateValuation = true;
                $seenTimestamps[$stamp] = true;
                if ($observation['currency'] !== $opening['currency']) {
                    $intermediateCurrencyMismatch = true;
                }
            }
            if ($duplicateValuation) {
                $out[$name] = [...$base, 'reason'=>'CONFLICTING_VALUATION_TIMESTAMPS'];
                continue;
            }
            if ($intermediateCurrencyMismatch) {
                $out[$name] = [...$base, 'reason'=>'INTERMEDIATE_NAV_CURRENCY_MISMATCH'];
                continue;
            }
            // Net return = NAV change - external contributions + external withdrawals.
            // Flows are cumulative since portfolio inception, in the valuation currency.
            $navChange = DecimalMath::subtract($closing['equity'], $opening['equity']);
            $externalFlowChange = DecimalMath::subtract($closing['cumulative_flow'], $opening['cumulative_flow']);
            $net = DecimalMath::subtract($navChange, $externalFlowChange);
            $out[$name] = [
                ...$base,
                'status' => 'COMPLETE',
                'net_pnl' => $net->value(),
                'currency' => $closing['currency'],
                'reason' => null,
                'opening_valued_at' => $opening['timestamp']->format(DATE_ATOM),
                'closing_valued_at' => $closing['timestamp']->format(DATE_ATOM),
                'external_flow_delta' => $externalFlowChange->value(),
            ];
        }
        return $out;
    }
    /** @param array<string,mixed> $snapshot */
    private static function verifiedSnapshotEvidence(array $snapshot):bool
    {
        if (!is_string($snapshot['provenance_id']??null)
            || trim($snapshot['provenance_id'])==='') return false;
        foreach (['ledger_fingerprint','marks_fingerprint','external_flows_fingerprint'] as $field) {
            if (!is_string($snapshot[$field]??null)
                || preg_match('/^[a-f0-9]{64}$/',$snapshot[$field])!==1) return false;
        }
        return true;
    }

}
