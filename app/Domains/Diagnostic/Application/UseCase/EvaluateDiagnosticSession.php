<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\Support\DiagnosticEvents;
use Domains\Diagnostic\Automation\Event\DiagnosticSessionEvaluated;
use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Input\DiagnosticSessionInputFactory;
use Domains\Diagnostic\Methodology\Result\DiagnosticResult;
use Domains\Diagnostic\Model\DiagnosticRecord;
use Domains\Diagnostic\Model\DiagnosticRecordType;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class EvaluateDiagnosticSession
{
    public function __construct(
        private DiagnosticPackRepositoryInterface $packs,
        private DiagnosticSessionRepositoryInterface $sessions,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private MethodologyEngine $engine = new MethodologyEngine(),
        private DiagnosticSessionInputFactory $inputs = new DiagnosticSessionInputFactory(),
    ) {
    }

    public function execute(
        string $organizationId,
        string $sessionId,
        DateTimeImmutable $now,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): DiagnosticResult {
        return $this->transactions->transactional(function () use ($organizationId, $sessionId, $now, $actorType, $actorId): DiagnosticResult {
            $session = $this->sessions->get($organizationId, $sessionId)
                ?? throw new DomainException('Diagnostic session was not found.');
            $pack = $this->packs->get($organizationId, $session->packId(), $session->packVersion())
                ?? throw new DomainException('Pinned diagnostic pack was not found.');
            $result = $this->engine->evaluate($this->inputs->create($session), $pack->methodology());
            $expectedLockVersion = $session->lockVersion();

            $inputRecords = [];
            foreach ($session->records() as $record) {
                if (in_array($record->type, [DiagnosticRecordType::Fact, DiagnosticRecordType::Metric], true)) {
                    $inputRecords[$record->criterionCode] = $record;
                }
            }
            foreach ($result->assessments as $assessment) {
                $id = 'assessment:' . $assessment->criterionId;
                if ($session->hasRecord($id)) throw new DomainException('Diagnostic session has already been evaluated.');
                $criterion = $this->criterion($pack->methodology()->criteria, $assessment->criterionId);
                $upstream = [];
                foreach (array_merge($criterion->required, $criterion->optional) as $reference) {
                    $code = str_starts_with($reference, 'fact.') ? substr($reference, 5) : (str_starts_with($reference, 'metric.') ? substr($reference, 7) : $reference);
                    if (isset($inputRecords[$code])) $upstream[] = $inputRecords[$code]->id;
                }
                $session->record(new DiagnosticRecord(
                    $id,
                    DiagnosticRecordType::Assessment,
                    $assessment->criterionId,
                    $assessment->score === null
                        ? sprintf(
                            'Insufficient data for %s (coverage %.2f, confidence %.2f; missing: %s).',
                            $assessment->criterionId,
                            $assessment->coverage->ratio,
                            $assessment->confidence,
                            $assessment->coverage->missingRequired === [] ? 'none' : implode(', ', $assessment->coverage->missingRequired),
                        )
                        : sprintf(
                            'Deterministic assessment for %s (coverage %.2f, confidence %.2f).',
                            $assessment->criterionId,
                            $assessment->coverage->ratio,
                            $assessment->confidence,
                        ),
                    $assessment->score,
                    'score',
                    $assessment->evidenceIds,
                    array_values(array_unique($upstream)),
                    $now,
                ), $pack);
            }
            foreach ($result->findings as $finding) {
                $assessmentId = 'assessment:' . $finding->criterionId;
                $session->record(new DiagnosticRecord(
                    'finding:' . $finding->ruleId,
                    DiagnosticRecordType::Finding,
                    $finding->criterionId,
                    $finding->statement,
                    $finding->severity,
                    'severity',
                    $finding->evidenceIds,
                    [$assessmentId],
                    $now,
                ), $pack);
            }

            $this->sessions->save($organizationId, $session, $expectedLockVersion);
            $this->events->publish(DiagnosticSessionEvaluated::create(
                DiagnosticEvents::id(), $organizationId, $sessionId, $result,
                DiagnosticEvents::metadata($actorType, $actorId), $now,
            ));
            return $result;
        });
    }

    private function criterion(array $criteria, string $id): object
    {
        foreach ($criteria as $criterion) if ($criterion->id === $id) return $criterion;
        throw new DomainException('Methodology criterion was not found: ' . $id);
    }
}
