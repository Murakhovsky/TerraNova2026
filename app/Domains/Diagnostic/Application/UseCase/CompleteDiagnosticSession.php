<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use DateTimeImmutable;
use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\Support\DiagnosticEvents;
use Domains\Diagnostic\Automation\Event\DiagnosticSessionCompleted;
use DomainException;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class CompleteDiagnosticSession
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
        DateTimeImmutable $now,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): void {
        $this->transactions->transactional(function () use ($organizationId, $sessionId, $now, $actorType, $actorId): void {
            $session = $this->sessions->get($organizationId, $sessionId)
                ?? throw new DomainException('Diagnostic session was not found.');
            $pack = $this->packs->get($organizationId, $session->packId(), $session->packVersion())
                ?? throw new DomainException('Pinned diagnostic pack was not found.');
            $expectedLockVersion = $session->lockVersion();
            $session->complete($now, $pack);
            $this->sessions->save($organizationId, $session, $expectedLockVersion);
            $this->events->publish(DiagnosticSessionCompleted::create(
                DiagnosticEvents::id(),
                $organizationId,
                $session,
                DiagnosticEvents::metadata($actorType, $actorId),
                $now,
            ));
        });
    }
}
