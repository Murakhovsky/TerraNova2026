<?php

declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Web\Diagnostic\ViewModel\DiagnosticReportViewModel;

final class DiagnosticReportPresenter
{
    /** @param array<string,mixed> $envelope */
    public function present(string $sessionId, array $envelope): DiagnosticReportViewModel
    {
        $report = is_array($envelope['report'] ?? null) ? $envelope['report'] : [];
        $healthValue = (float) ($report['overallHealth'] ?? 0);
        $sections = [];

        foreach ([
            'topProblems' => ['Priority', 'Top problems'],
            'criticalRisks' => ['Risk', 'Critical risks'],
            'opportunities' => ['Potential', 'Opportunities'],
            'strengths' => ['Capability', 'Strengths'],
            'quickWins' => ['Execution', 'Quick wins'],
            'findings' => ['Evidence', 'Findings'],
            'rootCauses' => ['Diagnosis', 'Root causes'],
            'recommendations' => ['Action', 'Recommendations'],
            'roadmap' => ['Sequence', 'Roadmap'],
            'unknowns' => ['Uncertainty', 'Unknowns'],
            'contradictions' => ['Evidence quality', 'Contradictions'],
        ] as $key => [$eyebrow, $title]) {
            $items = is_array($report[$key] ?? null) ? $report[$key] : [];
            if ($items === []) {
                continue;
            }

            $sections[] = [
                'title' => $title,
                'eyebrow' => $eyebrow,
                'items' => array_map(fn (mixed $item): array => $this->item($item), array_values($items)),
            ];
        }

        $measurements = [];
        foreach (is_array($envelope['measurements'] ?? null) ? $envelope['measurements'] : [] as $measurement) {
            if (!is_array($measurement)) {
                continue;
            }

            $measurements[] = [
                'code' => (string) ($measurement['metric_code'] ?? 'metric'),
                'value' => (string) ($measurement['metric_value'] ?? ''),
                'measuredAt' => (string) ($measurement['measured_at'] ?? ''),
            ];
        }

        return new DiagnosticReportViewModel(
            sessionId: $sessionId,
            title: (string) ($report['diagnosticId'] ?? $sessionId),
            executiveSummary: (string) ($report['executiveSummary'] ?? ''),
            reportVersion: (string) ($envelope['report_version'] ?? '1'),
            health: number_format($healthValue, 1) . '/100',
            healthTone: $healthValue >= 70 ? 'positive' : ($healthValue >= 40 ? 'warning' : 'danger'),
            coverage: $this->percent($report['coverage'] ?? 0),
            confidence: $this->percent($report['confidence'] ?? 0),
            sections: $sections,
            measurements: $measurements,
        );
    }

    /** @return array<string,string> */
    private function item(mixed $item): array
    {
        if (!is_array($item)) {
            return ['value' => $this->stringify($item)];
        }

        $result = [];
        foreach ($item as $key => $value) {
            $result[(string) $key] = $this->stringify($value);
        }

        return $result;
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) ($value ?? '');
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function percent(mixed $value): string
    {
        return number_format((float) $value * 100, 1) . '%';
    }
}
