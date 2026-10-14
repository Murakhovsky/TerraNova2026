<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionEvidenceAdapterInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Domain-origin inbound response, linked to a previously completed Action of
 * the same Workflow. A reply is not counted as a successful outbound Action.
 */
final readonly class GrowthInboundResponseNonActionEvidence implements WorkflowNonActionEvidenceAdapterInterface
{
    public function __construct(private Connection $db) {}

    public function kind(): string { return 'growth.inbound_response.recorded.v1'; }
    public function ownerDomain(): string { return 'growth'; }

    public function assertVerified(
        WorkflowRunIdentity $identity, string $stepId, string $reference, array $evidence,
    ): void {
        $response = $evidence['response_id'] ?? null;
        $source = $evidence['source_event_id'] ?? null;
        $actionId = $evidence['action_id'] ?? null;
        if (!is_string($response) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $response)
            || !is_string($source) || $source === '' || strlen($source) > 191
            || !is_string($actionId) || !preg_match('/^[a-f0-9]{32}$/D', $actionId)) {
            throw new DomainException('Growth response pin lacks valid source/action identifiers.');
        }
        $row = $this->db->fetchAssociative(
            'SELECT x.response_id,x.source_event_id,x.action_id,x.body,x.body_hash,
                    x.provider_reference,x.occurred_at,x.created_at,
                    a.status AS action_status,a.idempotency_key AS action_key,
                    r.created_at AS run_started,st.updated_at AS step_completed
             FROM tn_growth_engagement_responses x
             JOIN cos_actions a ON a.organization_id=x.organization_id AND a.id=x.action_id
             JOIN cos_workflow_runs r ON r.organization_id=x.organization_id
             JOIN cos_workflow_steps st
               ON st.organization_id=r.organization_id AND st.run_id=r.run_id
             WHERE x.organization_id=:org AND x.response_id=:response
               AND x.source_event_id=:source AND x.action_id=:action
               AND r.run_id=:run AND st.step_id=:step
               AND EXISTS (
                 SELECT 1 FROM cos_workflow_steps predecessor
                 WHERE predecessor.organization_id=r.organization_id AND predecessor.run_id=r.run_id
                   AND predecessor.step_id<>st.step_id AND predecessor.state="completed"
                   AND predecessor.side_effect_level="external"
                   AND predecessor.result_reference=CONCAT("action:",x.action_id)
                   AND a.idempotency_key=CONCAT("wf:",predecessor.idempotency_key)
               )',
            ['org'=>$identity->organizationId,'response'=>$response,
                'source'=>$source,'action'=>$actionId,
                'run'=>$identity->runId,'step'=>$stepId],
        );
        if (!$row || $row['action_status'] !== 'COMPLETED'
            || !is_string($row['provider_reference']) || $row['provider_reference'] === ''
            || !preg_match('/^[a-f0-9]{64}$/D', (string) $row['body_hash'])
            || !hash_equals((string) $row['body_hash'], hash('sha256', (string) $row['body']))
            || $row['occurred_at'] < $row['run_started']
            || $row['occurred_at'] > $row['step_completed']
            || $row['created_at'] < $row['run_started']
            || $row['created_at'] > $row['step_completed']) {
            throw new DomainException('Growth source response cannot prove fresh Run-linked inbound reply.');
        }
        $digest = hash('sha256', implode("\0", [
            (string) $row['body_hash'], (string) $row['source_event_id'],
            (string) $row['action_id'], (string) $row['provider_reference'],
        ]));
        $expected = 'growth:response:recorded:v1:' . $response . ':' . $digest;
        if (!hash_equals($expected, $reference)) {
            throw new DomainException('Growth response fingerprint differs from source record.');
        }
    }
}
