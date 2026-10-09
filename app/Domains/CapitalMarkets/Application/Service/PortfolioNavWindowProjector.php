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
        // Invalid records are evidence gaps, not silently removable observations.
        // A snapshot without a usable timestamp may belong to any window.
        $rejected = [];
        foreach ($snapshots as $snapshot) {
            $timestamp = null;
            if (is_array($snapshot) && is_string($snapshot['valued_at'] ?? null)) {
                try {
                    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/', $snapshot['valued_at']) === 1) {
                        $timestamp = (new DateTimeImmutable($snapshot['valued_at'], $utc))->setTimezone($utc);
                    }
                } catch (Throwable) {
                    // Unparseable source time must fail closed.
                }
            }
            if (!is_array($snapshot)
                || ($snapshot['valuation_status'] ?? '') !== 'COMPLETE'
                || ($snapshot['ledger_reconciled'] ?? false) !== true
                || ($snapshot['marks_reconciled'] ?? false) !== true
                || ($snapshot['external_flows_reconciled'] ?? false) !== true
                || !self::verifiedSnapshotEvidence($snapshot)
                || !is_string($snapshot['equity'] ?? null)
                || !is_string($snapshot['cumulative_external_net_flow'] ?? null)
                || !is_string($snapshot['currency'] ?? null)
                || $timestamp === null
            ) {
                $rejected[] = $timestamp;
                continue;
            }
            try {
                $equity = Decimal::fromString($snapshot['equity']);
                $flow = Decimal::fromString($snapshot['cumulative_external_net_flow']);
                $currency = strtoupper(trim($snapshot['currency']));
                if ($timestamp > $at) continue;
                if ($currency === '' || $equity->isNegative()) {
                    $rejected[] = $timestamp;
                    continue;
                }
                $valid[] = [
                    'timestamp' => $timestamp,
                    'equity' => $equity,
                    'cumulative_flow' => $flow,
                    'currency' => $currency,
                ];
            } catch (Throwable) {
                $rejected[] = $timestamp;
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
            foreach ($rejected as $unverifiedTime) {
                if ($unverifiedTime === null || ($unverifiedTime >= $start && $unverifiedTime <= $at)) {
                    $base['reason'] = 'UNVERIFIED_VALUATION_IN_WINDOW';
                    break;
                }
            }
            if ($base['reason'] === 'UNVERIFIED_VALUATION_IN_WINDOW') {
                $out[$name] = $base;
                continue;
            }
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
            if ($closing['timestamp'] <= $opening['timestamp']) {
                $out[$name] = [...$base, 'reason'=>'INSUFFICIENT_DISTINCT_NAV_VALUATIONS'];
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
    /**
     * The latest reconciled valuation can establish current NAV equity even if
     * opening Today / 30D snapshots are still missing. One fresh snapshot is
     * NOT evidence of positive or zero performance for any time window.
     *
     * @param list<array<string,mixed>> $snapshots
     * @return array<string,mixed>
     */
    public static function latestVerified(
        array $snapshots, ?DateTimeImmutable $at = null, int $maxAgeSeconds = 900,
    ): array {
        $now = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $result = ['status'=>'UNAVAILABLE','equity'=>null,'currency'=>null,
            'valued_at'=>null,'provenance_id'=>null,'reason'=>'MISSING_RECONCILED_NAV'];
        $best = null;
        $seen=[];
        foreach ($snapshots as $row) {
            if (!is_array($row) || !is_string($row['valued_at'] ?? null)) continue;
            try {
                if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/',$row['valued_at'])!==1) continue;
                $stamp=(new DateTimeImmutable($row['valued_at']))->setTimezone(new DateTimeZone('UTC'));
                $age=$now->getTimestamp()-$stamp->getTimestamp();
                if ($age < 0 || $age > $maxAgeSeconds) continue;
                $key=$stamp->format('Y-m-d H:i:s.u');
                if (isset($seen[$key])) return [...$result,'reason'=>'CONFLICTING_RECENT_NAV_TIMESTAMPS'];
                $seen[$key]=true;
                if (($row['valuation_status']??null)!=='COMPLETE'
                    || ($row['ledger_reconciled']??false)!==true
                    || ($row['marks_reconciled']??false)!==true
                    || ($row['external_flows_reconciled']??false)!==true
                    || !self::verifiedSnapshotEvidence($row)) {
                    return [...$result,'reason'=>'UNVERIFIED_RECENT_NAV'];
                }
                $equity=Decimal::fromString((string)($row['equity']??''));
                Decimal::fromString((string)($row['cumulative_external_net_flow']??''));
                $currency=strtoupper(trim((string)($row['currency']??'')));
                if ($currency==='' || $equity->isNegative()) {
                    return [...$result,'reason'=>'INVALID_RECENT_NAV_VALUE'];
                }
                if ($best===null || $stamp>$best['timestamp']) {
                    $best=['timestamp'=>$stamp,'equity'=>$equity->value(),
                        'currency'=>$currency,'provenance_id'=>$row['provenance_id']];
                }
            } catch (Throwable) {
                return [...$result,'reason'=>'INVALID_RECENT_NAV_EVIDENCE'];
            }
        }
        if ($best===null) return $result;
        return ['status'=>'COMPLETE','equity'=>$best['equity'],
            'currency'=>$best['currency'],'valued_at'=>$best['timestamp']->format(DATE_ATOM),
            'provenance_id'=>$best['provenance_id'],'reason'=>null];
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
