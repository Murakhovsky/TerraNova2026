<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use DateTimeImmutable;
use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\DTO\StartDiagnosticSessionCommand;
use Domains\Diagnostic\Application\Support\DiagnosticEvents;
use Domains\Diagnostic\Automation\Event\DiagnosticSessionStarted;
use Domains\Diagnostic\Model\DiagnosticPackStatus;
use Domains\Diagnostic\Model\DiagnosticSession;
use DomainException;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class StartDiagnosticSession
{
    public function __construct(
        private DiagnosticPackRepositoryInterface $packs,
        private DiagnosticSessionRepositoryInterface $sessions,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(
        StartDiagnosticSessionCommand $command,
        DateTimeImmutable $now,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): DiagnosticSession {
        return $this->transactions->transactional(function () use ($command, $now, $actorType, $actorId): DiagnosticSession {
            $pack = $this->packs->get($command->organizationId, $command->packId, $command->packVersion)
                ?? throw new DomainException('Diagnostic pack was not found.');
            if ($pack->status() !== DiagnosticPackStatus::Published) {
                throw new DomainException('A diagnostic session requires a published pack version.');
            }
            if ($pack->targetDomain() !== $command->target->domain) {
                throw new DomainException('Diagnostic target does not match the pack target domain.');
            }
            if ($this->sessions->get($command->organizationId, $command->sessionId) !== null) {
                throw new DomainException('Diagnostic session already exists.');
            }

            $session = new DiagnosticSession(
                $command->sessionId,
                $command->packId,
                $command->packVersion,
                $command->target,
            );
            $session->start($now);
            $this->sessions->save($command->organizationId, $session, null);
            $this->events->publish(DiagnosticSessionStarted::create(
                DiagnosticEvents::id(),
                $command->organizationId,
                $session,
                DiagnosticEvents::metadata($actorType, $actorId),
                $now,
            ));
            return $session;
        });
    }
}
