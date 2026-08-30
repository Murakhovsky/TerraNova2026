<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Input;

use Domains\Diagnostic\Model\DiagnosticRecordType;
use Domains\Diagnostic\Model\DiagnosticSession;
use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;
use InvalidArgumentException;

final class DiagnosticSessionInputFactory
{
    public function create(DiagnosticSession $session): DiagnosticInput
    {
        $evidence = [];
        foreach ($session->evidence() as $item) $evidence[$item->id] = $item;
        $facts = []; $metrics = [];
        foreach ($session->records() as $record) {
            if (!in_array($record->type, [DiagnosticRecordType::Fact, DiagnosticRecordType::Metric], true)) continue;
            $target = $record->type === DiagnosticRecordType::Fact ? 'fact' : 'metric';
            if (($target === 'fact' && isset($facts[$record->criterionCode])) || ($target === 'metric' && isset($metrics[$record->criterionCode]))) {
                throw new InvalidArgumentException(sprintf('Session has more than one %s value for %s.', $target, $record->criterionCode));
            }
            $signals = [];
            foreach ($record->evidenceIds as $evidenceId) {
                if (isset($evidence[$evidenceId])) $signals[] = $this->signal($evidence[$evidenceId], $record->value);
            }
            $observed = new ObservedValue($record->value, $signals);
            if ($target === 'fact') $facts[$record->criterionCode] = $observed;
            else $metrics[$record->criterionCode] = $observed;
        }
        return new DiagnosticInput($facts, $metrics, $session->completedAt() ?? $session->startedAt());
    }

    private function signal(Evidence $evidence, mixed $claim): EvidenceSignal
    {
        $reliability = (float) ($evidence->metadata['confidence'] ?? match ($evidence->type) {
            EvidenceType::SystemData => 0.95,
            EvidenceType::ExternalSource => 0.85,
            EvidenceType::Document => 0.80,
            EvidenceType::Survey => 0.70,
            EvidenceType::Interview, EvidenceType::Observation => 0.60,
        });
        return new EvidenceSignal(
            $evidence->id,
            $evidence->type->value,
            max(0.0, min(1.0, $reliability)),
            max(0.0, min(1.0, (float) ($evidence->metadata['quality'] ?? 1.0))),
            $evidence->capturedAt,
            $evidence->metadata['claim'] ?? $claim,
        );
    }
}
