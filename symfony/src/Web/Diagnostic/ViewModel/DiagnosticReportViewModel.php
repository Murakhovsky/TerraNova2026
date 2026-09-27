<?php

declare(strict_types=1);

namespace App\Web\Diagnostic\ViewModel;

final readonly class DiagnosticReportViewModel
{
    /**
     * @param list<array{title:string,eyebrow:string,items:list<array<string,string>>}> $sections
     * @param list<array{code:string,value:string,measuredAt:string}> $measurements
     */
    public function __construct(
        public string $sessionId,
        public string $title,
        public string $executiveSummary,
        public string $reportVersion,
        public string $health,
        public string $healthTone,
        public string $coverage,
        public string $confidence,
        public array $sections,
        public array $measurements,
    ) {
    }
}
