<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\Support\DiagnosticEvents;
use Domains\Diagnostic\Automation\Event\DiagnosticEvidenceCaptured;
use Domains\Diagnostic\Model\Evidence;
use DomainException;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class CaptureDiagnosticEvidence
{
    public function __construct(
        private DiagnosticSessionRepositoryInterface $sessions,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(
        string $organizationId,
        string $sessionId,
        Evidence $evidence,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): void
    {
        $this->transactions->transactional(function () use ($organizationId, $sessionId, $evidence, $actorType, $actorId): void {
            $session = $this->sessions->get($organizationId, $sessionId)
                ?? throw new DomainException('Diagnostic session was not found.');
            $expectedLockVersion = $session->lockVersion();
            $session->capture($evidence);
            $this->sessions->save($organizationId, $session, $expectedLockVersion);
            $this->events->publish(DiagnosticEvidenceCaptured::create(
                DiagnosticEvents::id(),
                $organizationId,
                $sessionId,
                $evidence,
                DiagnosticEvents::metadata($actorType, $actorId),
            ));
        });
    }
}
