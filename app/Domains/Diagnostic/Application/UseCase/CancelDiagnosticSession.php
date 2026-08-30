<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\Support\DiagnosticEvents;
use Domains\Diagnostic\Automation\Event\DiagnosticSessionCancelled;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class CancelDiagnosticSession
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
        DateTimeImmutable $now,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): void {
        $this->transactions->transactional(function () use ($organizationId, $sessionId, $now, $actorType, $actorId): void {
            $session = $this->sessions->get($organizationId, $sessionId)
                ?? throw new DomainException('Diagnostic session was not found.');
            $expectedLockVersion = $session->lockVersion();
            $session->cancel();
            $this->sessions->save($organizationId, $session, $expectedLockVersion);
            $this->events->publish(DiagnosticSessionCancelled::create(
                DiagnosticEvents::id(), $organizationId, $sessionId,
                DiagnosticEvents::metadata($actorType, $actorId), $now,
            ));
        });
    }
}
