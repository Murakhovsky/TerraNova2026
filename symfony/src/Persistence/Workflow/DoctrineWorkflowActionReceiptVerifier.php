<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Model\WorkflowReceiptAttestation;
use Platform\Workflow\Model\WorkflowReceiptRequest;

/**
 * Independent READ-ONLY canonical Action receipt attestation.
 *
 * Not wired as production WorkflowReceiptVerifierInterface until a trusted
 * Plan-approval adapter, live module/capability binding and execution path
 * are proven. Does not re-submit, approve, mutate or retry any Action.
 */
final readonly class DoctrineWorkflowActionReceiptVerifier implements WorkflowReceiptVerifierInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowPlanApprovalEvidenceInterface $plans,
    ) {}

    public function attest(WorkflowReceiptRequest $request): WorkflowReceiptAttestation
    {
        if (!$request->actor->isManager() || !$request->actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Workflow Action receipt requires tenant manager authorization.');
        }
        if (!preg_match('/^action:([a-f0-9]{32})$/', $request->resultReference, $matched)) {
            throw new DomainException('Workflow Action receipt has an invalid canonical identifier.');
        }
        $keys = ['org' => $request->identity->organizationId,
            'run' => $request->identity->runId, 'step' => $request->stepId];
        $context = $this->db->fetchAssociative(
            'SELECT r.state AS run_state, r.input_digest, r.definition_json,
                    r.source_plan_id, r.source_plan_digest, r.approval_reference,
                    r.actor_id, r.correlation_id,
                    s.state AS step_state, s.capability_id, s.capability_version,
                    s.side_effect_level, s.idempotency_key, s.result_reference
             FROM cos_workflow_runs r
             INNER JOIN cos_workflow_steps s
               ON s.organization_id=r.organization_id AND s.run_id=r.run_id
             WHERE r.organization_id=:org AND r.run_id=:run AND s.step_id=:step',
            $keys,
        );
        if (!$context
            || !in_array($context['run_state'], ['running','waiting','completed'], true)
            || !in_array($context['step_state'], ['claimed','completed'], true)
            || $context['side_effect_level'] !== 'external'
            || $context['input_digest'] !== $request->inputDigest
            || !preg_match('/^[a-f0-9]{64}$/', (string) $context['idempotency_key'])
            || ($context['result_reference'] !== null
                && $context['result_reference'] !== $request->resultReference)
            || !is_string($context['source_plan_id']) || $context['source_plan_id'] === ''
            || !preg_match('/^[a-f0-9]{64}$/', (string) $context['source_plan_digest'])
            || !is_string($context['approval_reference']) || $context['approval_reference'] === '') {
            throw new DomainException('Workflow receipt has no pinned, tenant-owned approved external Step.');
        }

        // The active Plan verification port must revalidate immutable plan
        // provenance and real human approvals, NOT only a plan's text flag.
        $this->plans->assertTrusted(
            $request->identity, (string) $context['source_plan_id'],
            (string) $context['source_plan_digest'], (string) $context['approval_reference'],
        );

        $definition = json_decode((string) $context['definition_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($definition) || !array_is_list($definition)) {
            throw new DomainException('Workflow immutable definition cannot be decoded.');
        }
        $step = null;
        foreach ($definition as $candidate) {
            if (($candidate['id'] ?? null) === $request->stepId) {
                if ($step !== null) throw new DomainException('Duplicate approved Workflow Step.');
                $step = $candidate;
            }
        }
        if (!is_array($step) || ($step['capability_id'] ?? null) !== $context['capability_id']
            || ($step['capability_version'] ?? null) !== $context['capability_version']
            || ($step['side_effect_level'] ?? null) !== 'external'
            || !is_string($step['execution_binding'] ?? null)
            || !str_starts_with($step['execution_binding'], 'action:')
            || !is_array($step['input'] ?? null)
            || !is_array($step['input']['parameters'] ?? null)) {
            throw new DomainException('Workflow Action binding differs from the immutable approved Step.');
        }
        $action = $this->db->fetchAssociative(
            'SELECT id, status, type, target_type, target_id, parameters,
                    source_type, source_id, execution_mode, risk_level,
                    idempotency_key, correlation_id
             FROM cos_actions WHERE organization_id=:org AND id=:id
               AND idempotency_key=:key',
            ['org' => $keys['org'], 'id' => $matched[1],
                'key' => 'wf:' . $context['idempotency_key']],
        );
        if (!$action || $action['status'] !== 'COMPLETED'
            || $action['source_type'] !== 'USER'
            || $action['source_id'] !== $context['actor_id']
            || $action['execution_mode'] !== 'APPROVAL_REQUIRED'
            || $action['correlation_id'] !== $context['correlation_id']
            || $step['execution_binding'] !== 'action:' . $action['type']
            || ($step['input']['target_type'] ?? null) !== $action['target_type']
            || ($step['input']['target_id'] ?? null) !== $action['target_id']) {
            throw new DomainException('Workflow receipt has no matching completed canonical Action and provenance.');
        }
        $parameters = json_decode((string) $action['parameters'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($parameters)
            || self::normalize($parameters) !== self::normalize($step['input']['parameters'])) {
            throw new DomainException('Canonical Action parameters changed from the approved Workflow Step.');
        }
        $attempts = $this->db->fetchAllAssociative(
            'SELECT attempt, status, finished_at FROM cos_action_attempts
             WHERE organization_id=:org AND action_id=:id',
            ['org' => $keys['org'], 'id' => $matched[1]],
        );
        if (count($attempts) !== 1 || (int) $attempts[0]['attempt'] !== 1
            || $attempts[0]['status'] !== 'COMPLETED' || $attempts[0]['finished_at'] === null) {
            throw new DomainException('Canonical Action has no unique first successful persisted attempt.');
        }
        $policy = $this->db->fetchOne(
            'SELECT decision FROM cos_policy_evaluations
             WHERE organization_id=:org AND action_id=:id
             ORDER BY evaluated_at DESC, id DESC LIMIT 1',
            ['org' => $keys['org'], 'id' => $matched[1]],
        );
        if ($policy !== 'APPROVAL_REQUIRED') {
            throw new DomainException('Canonical Action has no active human-approval policy decision.');
        }
        $approvals = $this->db->fetchAllAssociative(
            'SELECT status, decided_by_type, decided_by_id, decided_at, expires_at
             FROM cos_approvals WHERE organization_id=:org AND action_id=:id',
            ['org' => $keys['org'], 'id' => $matched[1]],
        );
        if (count($approvals) !== 1 || $approvals[0]['status'] !== 'APPROVED'
            || $approvals[0]['decided_by_type'] !== 'USER'
            || !is_string($approvals[0]['decided_by_id'])
            || $approvals[0]['decided_by_id'] === ''
            || $approvals[0]['decided_by_id'] === $context['actor_id']
            || $approvals[0]['decided_at'] === null
            || ($approvals[0]['expires_at'] !== null
                && strtotime((string) $approvals[0]['expires_at'] . ' UTC') <= time())) {
            throw new DomainException('Canonical Action lacks independent valid human Approval.');
        }
        return new WorkflowReceiptAttestation(
            $request->identity, $request->stepId, true, 'canonical-action',
            $request->resultReference, (string) $context['correlation_id'],
        );
    }

    private static function normalize(mixed $value): string
    {
        $sort = static function (mixed $node) use (&$sort): mixed {
            if (!is_array($node)) return $node;
            if (!array_is_list($node)) ksort($node, SORT_STRING);
            foreach ($node as $key => $inner) $node[$key] = $sort($inner);
            return $node;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
