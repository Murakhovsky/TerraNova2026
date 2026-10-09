<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Throwable;

/**
 * UTC realized execution P&L windows. This is NOT portfolio mark-to-market P&L.
 * Do not aggregate across unverified settlement currencies or incomplete economics.
 */
final class RealizedPnlWindowProjector
{
    private const CLOSED_STATUSES = ['COMPLETED', 'COMPLETED_COMPENSATED', 'CLOSED'];

    /**
     * @param list<array<string,mixed>> $executions Tenant-scoped canonical execution rows, newest 5000 maximum.
     * @return array<string,array<string,mixed>>
     */
    public static function project(array $executions, ?DateTimeImmutable $at = null): array
    {
        $utc = new DateTimeZone('UTC');
        $at = ($at ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $today = new DateTimeImmutable($at->format('Y-m-d').' 00:00:00', $utc);
        $windows = [
            'today' => $today,
            '30d' => $at->modify('-30 days'),
        ];
        $dated = [];
        $undated = 0;
        foreach ($executions as $execution) {
            if (!is_array($execution) || !in_array(strtoupper((string)($execution['status'] ?? '')), self::CLOSED_STATUSES, true)) {
                continue;
            }
            $raw = (string)(
                strtoupper((string)($execution['status'] ?? '')) === 'CLOSED'
                    ? ($execution['closed_at'] ?? '')
                    : ($execution['executed_at'] ?? $execution['closed_at'] ?? '')
            );
            try {
                if (trim($raw) === '') {
                    $undated++;
                    continue;
                }
                $time = (new DateTimeImmutable($raw, $utc))->setTimezone($utc);
                if ($time > $at) {
                    $undated++;
                    continue;
                }
                $dated[] = ['time' => $time, 'execution' => $execution];
            } catch (Throwable) {
                $undated++;
            }
        }

        $out = [];
        foreach ($windows as $name => $from) {
            $total = Decimal::fromString('0');
            $count = 0;
            $complete = 0;
            $currency = null;
            $issues = [];
            foreach ($dated as $entry) {
                if ($entry['time'] < $from) {
                    continue;
                }
                $count++;
                $execution = $entry['execution'];
                $unit = strtoupper(trim((string)(
                    $execution['quote_asset']
                    ?? $execution['pnl_currency']
                    ?? $execution['settlement_currency']
                    ?? $execution['performance']['currency']
                    ?? ''
                )));
                if ($unit === '') {
                    $issues['CURRENCY_UNVERIFIED'] = true;
                    continue;
                }
                if ($currency !== null && $currency !== $unit) {
                    $issues['MIXED_CURRENCIES'] = true;
                    continue;
                }
                $performance = is_array($execution['performance'] ?? null) ? $execution['performance'] : [];
                $required = ['spot_price_pnl', 'derivative_price_pnl', 'funding_pnl', 'trading_fees', 'borrow_cost', 'network_costs', 'net_pnl'];
                $isDecimal = static fn(mixed $raw): bool =>
                    is_string($raw) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $raw) === 1;
                try {
                    $canonical = null;
                    if (count(array_diff($required, array_keys($performance))) === 0) {
                        foreach ($required as $field) {
                            if (!$isDecimal($performance[$field])) {
                                throw new \InvalidArgumentException('Incomplete relative-value performance field.');
                            }
                        }
                        $gross = DecimalMath::add(
                            DecimalMath::add(
                                Decimal::fromString($performance['spot_price_pnl']),
                                Decimal::fromString($performance['derivative_price_pnl']),
                            ),
                            Decimal::fromString($performance['funding_pnl']),
                        );
                        $costs = DecimalMath::add(
                            DecimalMath::add(
                                DecimalMath::abs(Decimal::fromString($performance['trading_fees'])),
                                DecimalMath::abs(Decimal::fromString($performance['borrow_cost'])),
                            ),
                            DecimalMath::abs(Decimal::fromString($performance['network_costs'])),
                        );
                        if (DecimalMath::subtract($gross, $costs)->compareTo(Decimal::fromString($performance['net_pnl'])) !== 0) {
                            $issues['ECONOMICS_MISMATCH'] = true;
                            continue;
                        }
                        $canonical = $performance['net_pnl'];
                    } elseif (is_array($execution['fees'] ?? null) && array_key_exists('realized_pnl', $execution)) {
                        $fees = $execution['fees'];
                        if (!isset($fees['buy'], $fees['sell']) || !$isDecimal($fees['buy']) || !$isDecimal($fees['sell'])) {
                            $issues['ECONOMICS_INCOMPLETE'] = true;
                            continue;
                        }
                        $canonical = $execution['realized_pnl'];
                    }
                    if (!$isDecimal($canonical)) {
                        $issues['ECONOMICS_INCOMPLETE'] = true;
                        continue;
                    }
                    $total = DecimalMath::add($total, Decimal::fromString($canonical));
                    $currency = $unit;
                    $complete++;
                } catch (Throwable) {
                    $issues['ECONOMICS_INVALID'] = true;
                }
            }
            if ($undated !== 0) {
                $issues['UNDATED_EXECUTIONS'] = true;
            }
            if (count($executions) >= 5000) {
                $issues['SOURCE_PAGINATED'] = true;
            }
            $status = $count === 0 && $undated === 0
                ? 'UNAVAILABLE'
                : ($issues === [] && $complete === $count && $count > 0 ? 'COMPLETE' : 'PARTIAL');
            $out[$name] = [
                'status' => $status,
                'net_pnl' => $status === 'COMPLETE' ? $total->value() : null,
                'currency' => $status === 'COMPLETE' ? $currency : null,
                'coverage' => $complete.'/'.$count,
                'eligible_count' => $count,
                'unknown_timestamp_count' => $undated,
                'issues' => array_keys($issues),
                'from_utc' => $from->format(DATE_ATOM),
                'to_utc' => $at->format(DATE_ATOM),
                'scope' => 'REALIZED_EXECUTIONS_ONLY',
            ];
        }
        return $out;
    }
}
