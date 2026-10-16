<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DateTimeImmutable;
use DomainException;
use Doctrine\DBAL\Connection;
use Domains\Growth\Application\Contract\GrowthRunLinkedResponseReadModelInterface;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Throwable;

/**
 * Recorded provenance: Run -> completed Step -> independently attested
 * canonical Action -> persisted Growth response. No Action side effects.
 *
 * Uses admission directly instead of FederationExternalActionReceiptReconciler
 * to avoid a circular dependency through FederationGoalStore.
 */
final readonly class FederationGrowthRunLinkedOutcomeEvidenceProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION = 'growth.run_linked_inbound_responses';

    public function __construct(
        private Connection $db,
        private FederatedActionAdmission $admission,
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
        $org = $actor->organizationId()->value();
        $run = $this->db->fetchOne(
            'SELECT state FROM cos_federation_runs WHERE organization_id = :org AND run_id = :run',
            ['org' => $org, 'run' => $runId],
        );
        if ($run !== 'completed') {
            throw new DomainException('Run-linked evidence requires finalized tenant Run.');
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT s.result_reference, s.idempotency_key AS step_key,
                    a.id, a.status, a.type, a.target_type, a.target_id,
                    a.parameters, a.source_type, a.source_id, a.execution_mode,
                    a.risk_level, a.idempotency_key AS action_key, a.correlation_id
             FROM cos_federation_steps s
             LEFT JOIN cos_actions a ON a.organization_id = s.organization_id
               AND a.id = SUBSTRING(s.result_reference, 8)
               AND a.idempotency_key = CONCAT('fed:', s.idempotency_key)
             WHERE s.organization_id = :org AND s.run_id = :run
               AND s.side_effect_level = 'external' AND s.state = 'completed'
             ORDER BY s.step_id",
            ['org' => $org, 'run' => $runId],
        );
        $verified = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)
                || $row['result_reference'] !== 'action:' . $id
                || $row['action_key'] !== 'fed:' . $row['step_key']
                || $row['status'] !== 'COMPLETED') {
                continue;
            }
            try {
                $params = json_decode((string) $row['parameters'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($params)) continue;
                $completed = new Action(
                    $id, $org, (string) $row['type'],
                    $row['target_type'] !== null ? (string) $row['target_type'] : null,
                    $row['target_id'] !== null ? (string) $row['target_id'] : null,
                    $params, (string) $row['source_type'], (string) $row['source_id'],
                    (string) $row['execution_mode'], (string) $row['risk_level'],
                    (string) $row['action_key'], new DateTimeImmutable(),
                    ActionStatus::Completed, (string) $row['correlation_id'],
                );
                // Re-attest the canonical single completed attempt, immutable
                // approved inputs and independent human Policy/Approval.
                $this->admission->assertCompletedReceipt($completed);
                $verified[] = $id;
            } catch (Throwable) {
                // Malformed/revoked/cross-linked receipts never earn credit.
            }
        }
        $verified = array_values(array_unique($verified));
        $count = $this->responses->countForActions($org, $verified, $from, $to);
        if ($count < 0) {
            throw new DomainException('Negative Run-linked Growth outcome.');
        }
        $start = $from->format('Y-m-d\TH:i:s.uP');
        $end = $to->format('Y-m-d\TH:i:s.uP');
        $hash = hash('sha256', implode("\0", [
            'growth.run_linked_response.v1', $org, $runId, $criterionId,
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
