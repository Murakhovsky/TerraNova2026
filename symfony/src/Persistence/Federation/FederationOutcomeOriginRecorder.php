<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;

/**
 * Internal-only, append-only origin recorder. It must be called in the SAME
 * transaction as the authorized Domain Action's native business write.
 * No existing Research/Documents public endpoint invokes this service.
 */
final readonly class FederationOutcomeOriginRecorder
{
    public function __construct(
        private Connection $db,
        private FederatedActionAdmission $admission,
    ) {}

    public function record(Action $action, string $domain, string $outcomeId): void
    {
        FederationOutcomeOriginContract::assertTarget(
            $domain, $action->type, $action->targetType, $action->targetId, $outcomeId,
        );
        if (!$this->db->isTransactionActive()) {
            throw new DomainException('Domain outcome origin must be atomic with the business write.');
        }
        if ($action->status !== ActionStatus::Running) {
            throw new DomainException('Only a running canonical worker Action may create origin lineage.');
        }
        $persisted = $this->db->fetchAssociative(
            'SELECT status, type, target_type, target_id, idempotency_key
             FROM cos_actions WHERE organization_id=:org AND id=:action',
            ['org' => $action->organizationId, 'action' => $action->id],
        );
        if (!$persisted || $persisted['status'] !== 'RUNNING'
            || $persisted['type'] !== $action->type
            || $persisted['target_type'] !== $action->targetType
            || $persisted['target_id'] !== $action->targetId
            || $persisted['idempotency_key'] !== $action->idempotencyKey) {
            throw new DomainException('Origin requires identical durable running Action identity.');
        }
        // Canonical persisted Action, immutable Plan, worker-time policy,
        // independent human approval, tenant and claimed Step.
        $this->admission->assertAuthorized($action);

        $org = $action->organizationId;
        $step = $this->db->fetchAssociative(
            "SELECT s.run_id, s.step_id, r.created_at AS run_started_at FROM cos_federation_steps s
             INNER JOIN cos_federation_runs r ON r.organization_id=s.organization_id AND r.run_id=s.run_id
             WHERE s.organization_id=:org AND s.idempotency_key=:key
               AND s.side_effect_level='external' AND s.state='claimed'
               AND r.state IN ('running','waiting')",
            ['org' => $org, 'key' => substr((string) $action->idempotencyKey, 4)],
        );
        if (!$step) {
            throw new DomainException('Origin Action has no approved claimed Federation Step.');
        }

        $source = $this->nativeOutcome($org, $domain, $outcomeId);
        if ($source === null) {
            throw new DomainException('Native Research/Documents outcome is not final and verifiable.');
        }
        $eventTime = $source[$domain === 'capital_markets' ? 'created_at' : 'signed_at'];
        if ($eventTime < (string) $step['run_started_at']) {
            // Fail closed on second-precision Research boundary ambiguity.
            throw new DomainException('Native outcome predates the originating Federation Run.');
        }
        $this->db->insert('cos_federation_outcome_origins', [
            'organization_id' => $org,
            'domain_id' => $domain, 'outcome_id' => $outcomeId,
            'run_id' => (string) $step['run_id'], 'step_id' => (string) $step['step_id'],
            'action_id' => $action->id,
            'source_fingerprint' => self::fingerprint($org, $domain, $outcomeId, $source),
            'linked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** @return array<string,string>|null */
    public function nativeOutcome(string $org, string $domain, string $outcomeId): ?array
    {
        if ($org === '' || $outcomeId === '' || !FederationOutcomeOriginContract::canLink($domain)) {
            return null;
        }
        if ($domain === 'capital_markets') {
            $row = $this->db->fetchAssociative(
                "SELECT result_id, status, created_at, record_json
                 FROM tn_capital_market_research_results
                 WHERE organization_id=:org AND result_id=:id AND status='VALIDATED'",
                ['org' => $org, 'id' => $outcomeId],
            );
            if (!$row) return null;
            return [
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'record_hash' => hash('sha256', (string) $row['record_json']),
            ];
        }
        $row = $this->db->fetchAssociative(
            "SELECT signature_id, status, signed_at, signed_by, signature_reference
             FROM cos_document_signatures
             WHERE organization_id=:org AND signature_id=:id
               AND status='signed' AND signed_at IS NOT NULL
               AND signed_by IS NOT NULL AND signed_by <> ''
               AND signature_reference IS NOT NULL AND signature_reference <> ''",
            ['org' => $org, 'id' => $outcomeId],
        );
        if (!$row) return null;
        return [
            'status' => (string) $row['status'],
            'signed_at' => (string) $row['signed_at'],
            'signed_by' => (string) $row['signed_by'],
            'signature_reference' => (string) $row['signature_reference'],
        ];
    }

    /** @param array<string,string> $source */
    public static function fingerprint(string $org, string $domain, string $outcomeId, array $source): string
    {
        ksort($source, SORT_STRING);
        return hash('sha256', json_encode(
            ['v' => 1, 'organization_id' => $org, 'domain_id' => $domain,
             'outcome_id' => $outcomeId, 'source' => $source],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }
}
