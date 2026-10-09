<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Throwable;

/**
 * Read-only attestation of native Research / Documents outcomes against the
 * immutable origin journal and live canonical completed Action receipts.
 * Never uses a supplied correlation_id, Step state alone, or old API metadata.
 */
final readonly class FederationOutcomeOriginReader
{
    public function __construct(
        private Connection $db,
        private FederationOutcomeOriginRecorder $recorder,
        private FederatedActionAdmission $admission,
    ) {}

    /** @return list<array{outcome_id:string,action_id:string,step_id:string,source_fingerprint:string}> */
    public function verifiedForRun(
        TenantContext $viewer,
        string $runId,
        string $domain,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        if (!$viewer->isManager() || !$viewer->allows(TenantPermissions::MANAGE)
            || !FederationOutcomeOriginContract::canLink($domain)
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D', $runId)
            || $to <= $from) {
            throw new DomainException('Invalid or unauthorized Domain outcome origin inspection.');
        }
        $org = $viewer->organizationId()->value();
        $rows = $this->db->fetchAllAssociative(
            "SELECT l.outcome_id, l.run_id, l.step_id, l.action_id,
                    l.source_fingerprint, l.linked_at,
                    s.state AS step_state, s.side_effect_level, s.result_reference,
                    s.idempotency_key AS step_key,
                    a.id AS stored_action_id, a.status, a.type,
                    a.target_type, a.target_id, a.parameters, a.source_type,
                    a.source_id, a.execution_mode, a.risk_level,
                    a.idempotency_key AS action_key, a.correlation_id
             FROM cos_federation_outcome_origins l
             INNER JOIN cos_federation_runs r ON r.organization_id=l.organization_id
                AND r.run_id=l.run_id AND r.state='completed'
             LEFT JOIN cos_federation_steps s ON s.organization_id=l.organization_id
                AND s.run_id=l.run_id AND s.step_id=l.step_id
             LEFT JOIN cos_actions a ON a.organization_id=l.organization_id
                AND a.id=l.action_id
             WHERE l.organization_id=:org AND l.run_id=:run AND l.domain_id=:domain
             ORDER BY l.id ASC LIMIT 1001",
            ['org' => $org, 'run' => $runId, 'domain' => $domain],
        );
        if (count($rows) > 1000) {
            throw new DomainException('Domain origin inspection exceeds bounded evidence batch.');
        }
        $min = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $max = $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $valid = [];
        foreach ($rows as $row) {
            $actionId = (string) ($row['stored_action_id'] ?? '');
            $outcomeId = (string) $row['outcome_id'];
            if (!preg_match('/^[a-f0-9]{32}$/D', $actionId)
                || $actionId !== (string) $row['action_id']
                || $row['linked_at'] < $min || $row['linked_at'] >= $max
                || $row['step_state'] !== 'completed'
                || $row['side_effect_level'] !== 'external'
                || $row['result_reference'] !== 'action:' . $actionId
                || $row['action_key'] !== 'fed:' . $row['step_key']
                || $row['status'] !== 'COMPLETED') {
                continue;
            }
            try {
                FederationOutcomeOriginContract::assertTarget(
                    $domain, (string) $row['type'],
                    $row['target_type'] !== null ? (string) $row['target_type'] : null,
                    $row['target_id'] !== null ? (string) $row['target_id'] : null,
                    $outcomeId,
                );
                $source = $this->recorder->nativeOutcome($org, $domain, $outcomeId);
                if ($source === null
                    || !hash_equals((string) $row['source_fingerprint'],
                        FederationOutcomeOriginRecorder::fingerprint($org, $domain, $outcomeId, $source))) {
                    continue;
                }
                $params = json_decode((string) $row['parameters'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($params)) continue;
                $action = new Action(
                    $actionId, $org, (string) $row['type'],
                    $row['target_type'] !== null ? (string) $row['target_type'] : null,
                    $row['target_id'] !== null ? (string) $row['target_id'] : null,
                    $params, (string) $row['source_type'], (string) $row['source_id'],
                    (string) $row['execution_mode'], (string) $row['risk_level'],
                    (string) $row['action_key'], new DateTimeImmutable(),
                    ActionStatus::Completed, (string) $row['correlation_id'],
                );
                $this->admission->assertCompletedReceipt($action);
                $valid[] = [
                    'outcome_id' => $outcomeId, 'action_id' => $actionId,
                    'step_id' => (string) $row['step_id'],
                    'source_fingerprint' => (string) $row['source_fingerprint'],
                ];
            } catch (Throwable) {
                // Source modified, malformed or revoked Approval/Action:
                // fail closed. The row is kept for immutable audit.
            }
        }
        return $valid;
    }
}
