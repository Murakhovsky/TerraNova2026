<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\Support\DiagnosticEvents;
use Domains\Diagnostic\Automation\Event\DiagnosticRecordAdded;
use Domains\Diagnostic\Model\DiagnosticRecord;
use DomainException;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class RecordDiagnosticResult
{
    public function __construct(
        private DiagnosticPackRepositoryInterface $packs,
        private DiagnosticSessionRepositoryInterface $sessions,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(
        string $organizationId,
        string $sessionId,
        DiagnosticRecord $record,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): void
    {
        $this->transactions->transactional(function () use ($organizationId, $sessionId, $record, $actorType, $actorId): void {
            $session = $this->sessions->get($organizationId, $sessionId)
                ?? throw new DomainException('Diagnostic session was not found.');
            $pack = $this->packs->get($organizationId, $session->packId(), $session->packVersion())
                ?? throw new DomainException('Pinned diagnostic pack was not found.');
            $expectedLockVersion = $session->lockVersion();
            $session->record($record, $pack);
            $this->sessions->save($organizationId, $session, $expectedLockVersion);
            $this->events->publish(DiagnosticRecordAdded::create(
                DiagnosticEvents::id(),
                $organizationId,
                $sessionId,
                $record,
                DiagnosticEvents::metadata($actorType, $actorId),
            ));
        });
    }
}
