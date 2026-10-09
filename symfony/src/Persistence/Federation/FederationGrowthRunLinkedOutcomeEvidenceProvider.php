<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DateTimeImmutable;
use DomainException;
use Doctrine\DBAL\Connection;
use Domains\Growth\Application\Contract\GrowthRunLinkedResponseReadModelInterface;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;

/**
 * Verifiable recorded lineage: Run -> completed Step -> attested canonical
 * Action -> persisted Growth response. Does not assert economic causation.
 */
final readonly class FederationGrowthRunLinkedOutcomeEvidenceProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION = 'growth.run_linked_inbound_responses';

    public function __construct(
        private Connection $db,
        private FederationExternalActionReceiptReconciler $receipts,
        private GrowthRunLinkedResponseReadModelInterface $responses,
    ) {}

    public function domain(): string { return 'growth'; }

    public function supports(string $criterionId): bool
    {
        return $criterionId === self::CRITERION;
    }

    public function observe(
        string $organizationId, string $criterionId, DateTimeImmutable $from, DateTimeImmutable $to,
    ): array {
        throw new DomainException('Run-linked Growth outcomes require a verified Federation Run.');
    }

    public function observeRun(
        TenantContext $actor, string $runId, string $criterionId,
        DateTimeImmutable $from, DateTimeImmutable $to,
    ): array {
        if (!$this->supports($criterionId) || $to <= $from
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D', $runId)) {
            throw new DomainException('Invalid Run-linked Growth scope.');
        }
        $organizationId = $actor->organizationId()->value();
        $state = $this->db->fetchOne(
            'SELECT state FROM cos_federation_runs
             WHERE organization_id = :org AND run_id = :run',
            ['org' => $organizationId, 'run' => $runId],
        );
        if ($state !== 'completed') {
            throw new DomainException('Run-linked evidence requires completed tenant Run.');
        }
        $steps = $this->db->fetchAllAssociative(
            "SELECT step_id FROM cos_federation_steps
             WHERE organization_id = :org AND run_id = :run
               AND side_effect_level = 'external' AND state = 'completed'
             ORDER BY step_id",
            ['org' => $organizationId, 'run' => $runId],
        );
        $verified = [];
        foreach ($steps as $step) {
            // A completed Step uses the read-only branch of the reconciler.
            // Independently re-attest the Action against policies/approvals.
            $receipt = $this->receipts->reconcile($actor, $runId, (string) $step['step_id']);
            if ($receipt['status'] !== 'completed' || !is_string($receipt['action_id'] ?? null)) {
                continue;
            }
            $verified[] = $receipt['action_id'];
        }
        $verified = array_values(array_unique($verified));
        $count = $this->responses->countForActions($organizationId, $verified, $from, $to);
        if ($count < 0) {
            throw new DomainException('Negative Run-linked Growth outcome.');
        }
        $start = $from->format('Y-m-d\TH:i:s.uP');
        $end = $to->format('Y-m-d\TH:i:s.uP');
        $hash = hash('sha256', implode("\0", [
            'growth.run_linked_response.v1', $organizationId, $runId, $criterionId,
            $start, $end, (string) $count, ...$verified,
        ]));
        return [
            'value' => $count,
            'evidence' => ['growth:run_linked_response:v1:' . $hash],
            'source' => 'growth.inbound_responses.attested_action.v1',
            'window_start' => $start,
            'window_end' => $end,
            'attribution' => 'run_linked_action',
            'run_id' => $runId,
            'verified_actions' => count($verified),
        ];
    }
}
