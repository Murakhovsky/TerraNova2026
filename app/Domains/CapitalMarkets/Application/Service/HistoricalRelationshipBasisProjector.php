<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Throwable;

/**
 * Historical, non-executable relationship price basis.
 * Explicit economic equivalence evidence is mandatory, no implied 1:1 mapping.
 */
final class HistoricalRelationshipBasisProjector
{
    /** @param list<CanonicalMarketEvent> $sourceEvents @param list<CanonicalMarketEvent> $targetEvents
     *  @param array<string,mixed> $metadata
     *  @return array<string,mixed>
     */
    public static function project(
        array $sourceEvents,
        array $targetEvents,
        array $metadata,
        int $maxSkewSeconds = 30,
    ): array {
        $empty = [
            'status' => 'NOT COMPARABLE',
            'reason' => 'NO_VERIFIED_EQUIVALENCE',
            'rows' => [],
            'max_skew_seconds' => $maxSkewSeconds,
            'scope' => 'HISTORICAL_OBSERVATION_ONLY',
        ];
        $ratioRaw = $metadata['target_units_per_source_unit'] ?? null;
        if (($metadata['conversion_verified'] ?? false) !== true || !is_string($ratioRaw)) {
            return $empty;
        }
        try {
            $ratio = Decimal::fromString($ratioRaw);
            if (!$ratio->isPositive()) {
                return $empty;
            }
        } catch (Throwable) {
            return $empty;
        }
        if ($maxSkewSeconds < 0 || $maxSkewSeconds > 300) {
            return [...$empty, 'reason'=>'INVALID_TIME_TOLERANCE'];
        }
        $source = self::eligibleQuotes($sourceEvents);
        $target = self::eligibleQuotes($targetEvents);
        if ($source === [] || $target === []) {
            return [...$empty, 'reason'=>'NO_TRUSTED_LIVE_QUOTES'];
        }
        $rows = [];
        $used = [];
        $skewSeen = false;
        $unitMismatch = false;
        foreach ($source as $left) {
            $candidate = null;
            $candidateId = null;
            $distance = null;
            foreach ($target as $idx=>$right) {
                if (isset($used[$idx])) continue;
                if ($left['unit'] !== $right['unit']) {
                    $unitMismatch = true;
                    continue;
                }
                $skew = abs($left['unix'] - $right['unix']);
                if ($skew > $maxSkewSeconds) {
                    $skewSeen = true;
                    continue;
                }
                if ($distance === null || $skew < $distance) {
                    $candidate = $right;
                    $candidateId = $idx;
                    $distance = $skew;
                }
            }
            if ($candidate === null || $candidateId === null) continue;
            $used[$candidateId] = true;
            $reference = DecimalMath::multiply($candidate['value'], $ratio);
            if (!$reference->isPositive()) continue;
            $absolute = DecimalMath::subtract($left['value'], $reference);
            $basisBps = DecimalMath::basisPoints($absolute, $reference, 8);
            $rows[] = [
                'source_timestamp' => $left['timestamp'],
                'target_timestamp' => $candidate['timestamp'],
                'source_id' => $left['source'],
                'target_source_id' => $candidate['source'],
                'source_venue' => $left['venue'],
                'target_venue' => $candidate['venue'],
                'source_mid' => $left['value']->value(),
                'target_mid' => $candidate['value']->value(),
                'target_units_per_source_unit' => $ratio->value(),
                'reference_value' => $reference->value(),
                'basis_absolute' => $absolute->value(),
                'basis_bps' => $basisBps->value(),
                'quote_asset' => $left['unit'],
                'skew_seconds' => $distance,
            ];
        }
        return [
            'status' => $rows === [] ? 'NOT COMPARABLE' : 'OBSERVATIONS_AVAILABLE',
            'reason' => $rows === [] ? ($unitMismatch ? 'QUOTE_CURRENCY_MISMATCH' : ($skewSeen ? 'TIMESTAMPS_NOT_ALIGNED' : 'NO_MATCHING_OBSERVATIONS')) : null,
            'rows' => $rows,
            'max_skew_seconds' => $maxSkewSeconds,
            'scope' => 'HISTORICAL_OBSERVATION_ONLY',
        ];
    }

    /** @param list<CanonicalMarketEvent> $events
     *  @return list<array{timestamp:string,unix:int,source:string,venue:?string,unit:string,value:Decimal}>
     */
    private static function eligibleQuotes(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            if (!$event instanceof CanonicalMarketEvent) continue;
            if (!in_array($event->eventType()->value, ['QUOTE','BBO'], true)
                || $event->mode->value !== 'LIVE'
                || $event->marketStatus->value !== 'OPEN'
                || $event->qualityFlags !== []) continue;
            $payload = $event->observation->toArray();
            $unit = (string)($payload['ask_price']['quote_asset'] ?? '');
            $bidUnit = (string)($payload['bid_price']['quote_asset'] ?? '');
            $mid = $payload['mid_price'] ?? null;
            if ($unit === '' || $unit !== $bidUnit || !is_string($mid)) continue;
            try {
                $price = Decimal::fromString($mid);
                if (!$price->isPositive()) continue;
                $timestamp = $event->timestamps->sourceTimestamp;
                $rows[] = [
                    'timestamp' => $timestamp->format(DATE_ATOM),
                    'unix' => $timestamp->getTimestamp(),
                    'source' => $event->sourceId->value(),
                    'venue' => $event->venueId?->value(),
                    'unit' => $unit,
                    'value' => $price,
                ];
            } catch (Throwable) {
                continue;
            }
        }
        usort($rows, static fn(array $a,array $b):int => $a['unix'] <=> $b['unix']);
        return $rows;
    }
}
