<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Application\Support\DiagnosticEvents;
use Domains\Diagnostic\Automation\Event\DiagnosticPackDrafted;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Diagnostic\Model\DiagnosticPack;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class DraftDiagnosticPack
{
    public function __construct(
        private DiagnosticPackRepositoryInterface $packs,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(
        string $organizationId,
        string $targetDomain,
        MethodologyPack $methodology,
        DateTimeImmutable $now,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): DiagnosticPack {
        return $this->transactions->transactional(function () use ($organizationId, $targetDomain, $methodology, $now, $actorType, $actorId): DiagnosticPack {
            if ($this->packs->get($organizationId, $methodology->id, $methodology->version) !== null) {
                throw new DomainException('Diagnostic pack version already exists.');
            }
            $pack = DiagnosticPack::draft($targetDomain, $methodology);
            $this->packs->save($organizationId, $pack, null);
            $this->events->publish(DiagnosticPackDrafted::create(
                DiagnosticEvents::id(), $organizationId, $pack,
                DiagnosticEvents::metadata($actorType, $actorId), $now,
            ));
            return $pack;
        });
    }
}
