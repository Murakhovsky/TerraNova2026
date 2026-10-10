<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DateTimeImmutable;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;

/**
 * Domain-specific instances explicitly bind criterion, native origin and
 * owning capability. Fail-closed until the real Action writer is deployed,
 * end-to-end tested, and explicitly activated for this deployment.
 */
final readonly class FederationNativeRunLinkedOutcomeEvidenceProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(
        private FederationOutcomeOriginReader $reader,
        private FederationCapabilityBindingResolver $capabilities,
        private string $ownerDomain,
        private string $criterionId,
        private string $capabilityId,
        private bool $writerReady = false,
    ) {}

    public function domain(): string { return $this->ownerDomain; }

    public function supports(string $criterionId): bool
    {
        return $criterionId === $this->criterionId;
    }

    public function observe(
        string $organizationId, string $criterionId, DateTimeImmutable $from, DateTimeImmutable $to,
    ): array {
        throw new DomainException('Native outcome attribution requires an authenticated Run.');
    }

    public function observeRun(
        TenantContext $actor, string $runId, string $criterionId,
        DateTimeImmutable $from, DateTimeImmutable $to,
    ): array {
        if (!$this->supports($criterionId)
            || !FederationOutcomeOriginContract::canLink($this->ownerDomain)
            || $this->capabilityId !== FederationOutcomeOriginContract::actionType($this->ownerDomain)
            || $to <= $from) {
            throw new DomainException('Invalid native outcome Domain ownership or interval.');
        }
        if (!$this->writerReady) {
            // No native Action handler has been wired to the append-only writer.
            throw new FederationOutcomeSourceNotReady('Native Domain origin writer has not been activated.');
        }
        try {
            $capability = $this->capabilities->requireExecutable($actor, $this->capabilityId);
        } catch (DomainException) {
            throw new FederationOutcomeSourceNotReady('No live Domain-owned canonical Action handler.');
        }
        if ($capability->ownerDomain !== $this->ownerDomain
            || $capability->executionBinding !== 'action:' . $this->capabilityId
            || $capability->sideEffectLevel !== 'external'
            || $capability->approvalPolicy !== 'required'
            || $capability->idempotency !== 'required') {
            throw new DomainException('Native origin Action capability is not an approved external writer.');
        }
        $verified = $this->reader->verifiedForRun($actor, $runId, $this->ownerDomain, $from, $to);
        $ids = array_map(static fn (array $item): string => $item['source_fingerprint'], $verified);
        sort($ids, SORT_STRING);
        $start = $from->format('Y-m-d\TH:i:s.uP');
        $end = $to->format('Y-m-d\TH:i:s.uP');
        $hash = hash('sha256', implode("\0", [
            'native_run_outcomes.v1', $actor->organizationId()->value(),
            $this->ownerDomain, $runId, $criterionId, $start, $end,
            ...$ids,
        ]));
        return [
            'value' => count($verified),
            'evidence' => [$this->ownerDomain . ':native_run_origin:v1:' . $hash],
            'source' => $this->ownerDomain . '.federation_outcome_origins.v1',
            'window_start' => $start,
            'window_end' => $end,
            'attribution' => 'run_linked_action',
            'run_id' => $runId,
            'verified_actions' => count($verified),
        ];
    }
}
