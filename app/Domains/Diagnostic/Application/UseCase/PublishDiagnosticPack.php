<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use DateTimeImmutable;
use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Application\Support\DiagnosticEvents;
use Domains\Diagnostic\Automation\Event\DiagnosticPackPublished;
use Domains\Diagnostic\Model\Policy\PackPublicationPolicy;
use DomainException;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class PublishDiagnosticPack
{
    public function __construct(
        private DiagnosticPackRepositoryInterface $packs,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private PackPublicationPolicy $policy = new PackPublicationPolicy(),
    ) {
    }

    public function execute(
        string $organizationId,
        string $packId,
        int $version,
        DateTimeImmutable $now,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): void {
        $this->transactions->transactional(function () use ($organizationId, $packId, $version, $now, $actorType, $actorId): void {
            $pack = $this->packs->get($organizationId, $packId, $version)
                ?? throw new DomainException('Diagnostic pack was not found.');
            $expectedLockVersion = $pack->lockVersion();
            $pack->publish($now, $this->policy);
            $this->packs->save($organizationId, $pack, $expectedLockVersion);
            $this->events->publish(DiagnosticPackPublished::create(
                DiagnosticEvents::id(),
                $organizationId,
                $pack,
                DiagnosticEvents::metadata($actorType, $actorId),
                $now,
            ));
        });
    }
}
