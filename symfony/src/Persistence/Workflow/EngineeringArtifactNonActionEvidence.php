<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionEvidenceAdapterInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Read-only Engineering artifact proof. Verifies tenant via Feature ownership,
 * current active artifact, independently finished AgentRun and content hash.
 * Artifact existence is NOT a substitute for QA/Review/Release approval.
 */
final readonly class EngineeringArtifactNonActionEvidence implements WorkflowNonActionEvidenceAdapterInterface
{
    public function __construct(private Connection $db) {}

    public function kind(): string { return 'engineering.artifact.active.v1'; }
    public function ownerDomain(): string { return 'platform.engineering'; }

    public function assertVerified(
        WorkflowRunIdentity $identity, string $stepId, string $reference, array $evidence,
    ): void {
        $feature = $evidence['feature_id'] ?? null;
        $artifact = $evidence['artifact_id'] ?? null;
        $type = $evidence['artifact_type'] ?? null;
        $version = $evidence['version'] ?? null;
        $agentRun = $evidence['agent_run_id'] ?? null;
        foreach (['feature_id'=>$feature,'artifact_id'=>$artifact,'agent_run_id'=>$agentRun] as $key=>$value) {
            if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,36}$/D', $value)) {
                throw new DomainException('Engineering artifact pinned identifier invalid: ' . $key);
            }
        }
        if (!is_string($type) || !preg_match('/^[A-Z0-9_]{1,48}$/D', $type)
            || !is_int($version) || $version < 1) {
            throw new DomainException('Engineering artifact type/version is not pinned.');
        }
        $row = $this->db->fetchAssociative(
            'SELECT a.id,a.type,a.version,a.status,a.content,a.content_hash,a.created_at,
                    ar.id AS producer_id,ar.status AS producer_status,ar.output AS producer_output,
                    ar.started_at AS producer_started,ar.finished_at AS producer_finished,
                    r.created_at AS run_started,st.updated_at AS step_completed
             FROM cos_engineering_artifacts a
             JOIN cos_engineering_features f ON f.id=a.feature_id
             JOIN cos_engineering_agent_runs ar
               ON ar.id=a.agent_run_id AND ar.feature_id=a.feature_id
             JOIN cos_workflow_runs r ON r.organization_id=f.organization_id
             JOIN cos_workflow_steps st
               ON st.organization_id=r.organization_id AND st.run_id=r.run_id
             WHERE f.organization_id=:org AND f.id=:feature AND a.id=:artifact
               AND a.agent_run_id=:producer AND r.run_id=:run AND st.step_id=:step',
            ['org'=>$identity->organizationId,'feature'=>$feature,'artifact'=>$artifact,
                'producer'=>$agentRun,'run'=>$identity->runId,'step'=>$stepId],
        );
        if (!$row || $row['status'] !== 'ACTIVE' || $row['type'] !== $type
            || (int) $row['version'] !== $version
            || strtoupper((string) $row['producer_status']) !== 'COMPLETED'
            || $row['producer_output'] === null || $row['producer_finished'] === null
            || $row['producer_started'] < $row['run_started']
            || $row['producer_finished'] > $row['step_completed']
            || $row['created_at'] < $row['run_started']
            || $row['created_at'] > $row['step_completed']) {
            throw new DomainException('Engineering artifact is not a fresh, active and completed AgentRun result.');
        }
        $content = json_decode((string) $row['content'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($content) || $content === []) {
            throw new DomainException('Engineering artifact content is empty or corrupt.');
        }
        $this->normalize($content);
        $digest = hash('sha256', json_encode($content,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!preg_match('/^[a-f0-9]{64}$/D', (string) $row['content_hash'])
            || !hash_equals((string) $row['content_hash'], $digest)
            || !hash_equals('engineering:artifact:active:v1:' . $artifact . ':' . $digest, $reference)) {
            throw new DomainException('Engineering artifact content hash differs from authoritative ArtifactStore.');
        }
    }

    private function normalize(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) $this->normalize($item);
        }
        unset($item);
        if (!array_is_list($value)) ksort($value);
    }
}
