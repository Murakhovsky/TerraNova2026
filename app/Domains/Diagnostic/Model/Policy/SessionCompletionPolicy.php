<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model\Policy;

use DomainException;
use Domains\Diagnostic\Model\DiagnosticPack;
use Domains\Diagnostic\Model\DiagnosticRecordType;
use Domains\Diagnostic\Model\DiagnosticSession;

final class SessionCompletionPolicy
{
    public function assertSatisfied(DiagnosticSession $session, DiagnosticPack $pack): void
    {
        if ($pack->id() !== $session->packId() || $pack->version() !== $session->packVersion()) {
            throw new DomainException('Session completion must use the pinned diagnostic pack version.');
        }
        foreach ($pack->methodology()->criteria as $criterion) {
            $hasConclusion = false;
            foreach ($session->records() as $record) {
                if ($record->criterionCode === $criterion->id
                    && in_array($record->type, [DiagnosticRecordType::Assessment, DiagnosticRecordType::Finding], true)
                ) {
                    $hasConclusion = true;
                    break;
                }
            }
            if (!$hasConclusion) {
                throw new DomainException('Criterion has no assessment or finding: ' . $criterion->id);
            }
        }
    }
}
