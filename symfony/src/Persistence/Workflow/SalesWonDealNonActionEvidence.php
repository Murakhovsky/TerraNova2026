<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionEvidenceAdapterInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/** Read-only Sales won-deal fact from the canonical tenant-scoped event stream. */
final readonly class SalesWonDealNonActionEvidence implements WorkflowNonActionEvidenceAdapterInterface
{
    public function __construct(private Connection $db) {}

    public function kind(): string { return 'sales.deal.won.v1'; }
    public function ownerDomain(): string { return 'sales'; }

    public function assertVerified(
        WorkflowRunIdentity $identity, string $stepId, string $reference, array $evidence,
    ): void {
        $event = $evidence['event_id'] ?? null;
        $deal = $evidence['deal_id'] ?? null;
        if (!is_string($event) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $event)
            || !is_string($deal) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $deal)) {
            throw new DomainException('Sales won-deal source identifiers are invalid.');
        }
        $row = $this->db->fetchAssociative(
            'SELECT e.id,e.type,e.aggregate_type,e.aggregate_id,e.payload,
                    e.occurred_at,e.recorded_at,
                    r.created_at AS run_started,st.updated_at AS step_completed
             FROM cos_events e
             JOIN cos_workflow_runs r ON r.organization_id=e.organization_id
             JOIN cos_workflow_steps st
               ON st.organization_id=r.organization_id AND st.run_id=r.run_id
             WHERE e.organization_id=:org AND e.id=:event
               AND r.run_id=:run AND st.step_id=:step',
            ['org'=>$identity->organizationId,'event'=>$event,
                'run'=>$identity->runId,'step'=>$stepId],
        );
        if (!$row || $row['type'] !== 'sales.deal.won'
            || $row['aggregate_type'] !== 'deal' || $row['aggregate_id'] !== $deal
            || $row['occurred_at'] < $row['run_started']
            || $row['occurred_at'] > $row['step_completed']
            || $row['recorded_at'] < $row['run_started']
            || $row['recorded_at'] > $row['step_completed']) {
            throw new DomainException('No fresh tenant-owned Sales won-deal business event.');
        }
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new DomainException('Invalid Sales event payload.');
        }
        $expected = 'sales:deal:won:v1:' . $event . ':' . WorkflowDefinitionFingerprint::digest([$payload]);
        if (!hash_equals($expected, $reference)) {
            throw new DomainException('Sales event evidence fingerprint differs from canonical event.');
        }
    }
}
