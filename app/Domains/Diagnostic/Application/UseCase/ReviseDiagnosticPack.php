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

final readonly class ReviseDiagnosticPack
{
    public function __construct(
        private DiagnosticPackRepositoryInterface $packs,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(
        string $organizationId,
        string $packId,
        int $publishedVersion,
        MethodologyPack $revisionMethodology,
        DateTimeImmutable $now,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): DiagnosticPack {
        return $this->transactions->transactional(function () use ($organizationId, $packId, $publishedVersion, $revisionMethodology, $now, $actorType, $actorId): DiagnosticPack {
            $published = $this->packs->get($organizationId, $packId, $publishedVersion)
                ?? throw new DomainException('Published diagnostic pack was not found.');
            if ($this->packs->get($organizationId, $packId, $revisionMethodology->version) !== null) {
                throw new DomainException('Diagnostic pack revision already exists.');
            }
            $revision = $published->revise($revisionMethodology);
            $this->packs->save($organizationId, $revision, null);
            $this->events->publish(DiagnosticPackDrafted::create(
                DiagnosticEvents::id(), $organizationId, $revision,
                DiagnosticEvents::metadata($actorType, $actorId), $now,
            ));
            return $revision;
        });
    }
}
