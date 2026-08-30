<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use DateTimeImmutable;
use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Input\EvidenceSignal;
use Domains\Diagnostic\Methodology\Model\CriterionDefinition;

final class ConfidenceEngine
{
    public function evaluate(CriterionDefinition $criterion, DiagnosticInput $input): float
    {
        $signals = [];
        $agreements = [];
        foreach (array_merge($criterion->required, $criterion->optional) as $reference) {
            $observed = $input->get($reference);
            foreach ($observed?->evidence ?? [] as $signal) $signals[$signal->id] = $signal;
            $claims = array_values(array_filter(array_map(static fn (EvidenceSignal $signal): mixed => $signal->claim, $observed?->evidence ?? []), static fn (mixed $claim): bool => $claim !== null));
            if (count($claims) > 1) $agreements[] = $this->agreement($claims);
        }
        if ($signals === []) return 0.0;
        $now = $input->evaluatedAt ?? new DateTimeImmutable();
        $effective = array_map(fn (EvidenceSignal $signal): float => $signal->reliability * $signal->quality * $this->freshness($signal, $now), $signals);
        $base = array_sum($effective) / count($effective);
        $sourceCount = count(array_unique(array_map(static fn (EvidenceSignal $signal): string => $signal->sourceType, $signals)));
        $sourceFactor = min(1.0, 0.75 + (0.125 * $sourceCount));
        $agreement = $agreements === [] ? 1.0 : array_sum($agreements) / count($agreements);
        return round(min(1.0, $base * $sourceFactor * $agreement), 4);
    }

    private function freshness(EvidenceSignal $signal, DateTimeImmutable $now): float
    {
        if ($signal->capturedAt === null) return 1.0;
        if ($signal->capturedAt > $now) return 0.0;
        $days = max(0, (int) $signal->capturedAt->diff($now)->format('%a'));
        return match (true) { $days <= 30 => 1.0, $days <= 90 => 0.9, $days <= 180 => 0.75, default => 0.5 };
    }

    /** @param list<mixed> $claims */
    private function agreement(array $claims): float
    {
        if (count(array_filter($claims, 'is_numeric')) === count($claims)) {
            $numbers = array_map('floatval', $claims);
            $scale = max(1.0, abs(array_sum($numbers) / count($numbers)));
            return max(0.0, 1.0 - min(1.0, (max($numbers) - min($numbers)) / $scale));
        }
        $counts = array_count_values(array_map(static fn (mixed $claim): string => json_encode($claim, JSON_THROW_ON_ERROR), $claims));
        return max($counts) / count($claims);
    }
}
