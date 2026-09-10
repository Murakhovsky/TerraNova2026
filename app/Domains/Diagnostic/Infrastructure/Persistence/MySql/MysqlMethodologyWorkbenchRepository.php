<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Diagnostic\Application\Contract\MethodologyWorkbenchRepositoryInterface;
use PDO;
use Throwable;

final readonly class MysqlMethodologyWorkbenchRepository implements MethodologyWorkbenchRepositoryInterface
{
    private const PERMISSIONS = [
        'diagnostic.methodology.view',
        'diagnostic.methodology.edit',
        'diagnostic.methodology.publish',
    ];

    public function __construct(private PDO $database) {}

    public function scenarios(string $organizationId, string $packId, string $version): array
    {
        $statement = $this->database->prepare(
            'SELECT scenario.scenario_id,scenario.name,scenario.input_json,scenario.expected_json,
                result.status last_status,result.result_json last_result_json,result.created_at last_run_at
             FROM diagnostic_test_scenarios scenario
             LEFT JOIN diagnostic_test_results result
               ON result.organization_id=scenario.organization_id
              AND result.pack_id=scenario.pack_id
              AND result.methodology_version=scenario.methodology_version
              AND result.scenario_id=scenario.scenario_id
              AND result.created_at=(
                    SELECT MAX(latest.created_at)
                    FROM diagnostic_test_results latest
                    WHERE latest.organization_id=scenario.organization_id
                      AND latest.pack_id=scenario.pack_id
                      AND latest.methodology_version=scenario.methodology_version
                      AND latest.scenario_id=scenario.scenario_id
              )
             WHERE scenario.organization_id=:org
               AND scenario.pack_id=:pack
               AND scenario.methodology_version=:version
             ORDER BY scenario.name,scenario.scenario_id'
        );
        $statement->execute(['org' => $organizationId, 'pack' => $packId, 'version' => $version]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['input'] = self::decode($row['input_json'], []);
            $row['expected'] = self::decode($row['expected_json'], []);
            $row['last_result'] = self::decode($row['last_result_json'], null);
            unset($row['input_json'], $row['expected_json'], $row['last_result_json']);
        }
        return $rows;
    }

    public function diagnosticRun(string $organizationId, string $sessionId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT session.*,
                pack.methodology_json,
                (
                    SELECT ROUND(AVG(assessment.coverage)*100,1)
                    FROM diagnostic_assessment_results assessment
                    WHERE assessment.organization_id=session.organization_id
                      AND assessment.session_id=session.session_id
                      AND assessment.applicable=1
                ) coverage_percent
             FROM diagnostic_sessions session
             LEFT JOIN diagnostic_packs pack
               ON pack.organization_id=session.organization_id
              AND pack.pack_id=session.pack_id
              AND pack.version=session.pack_version
             WHERE session.organization_id=:org AND session.session_id=:session
             LIMIT 1'
        );
        $statement->execute(['org' => $organizationId, 'session' => $sessionId]);
        $session = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            return null;
        }

        $methodology = self::decode($session['methodology_json'] ?? null, []);
        unset($session['methodology_json']);

        $records = $this->rows(
            'SELECT record_id,record_type,reference_code,statement,value_json,unit,evidence_ids_json,upstream_record_ids_json,recorded_at
             FROM diagnostic_records
             WHERE organization_id=:org AND session_id=:session
             ORDER BY recorded_at,record_id',
            $organizationId,
            $sessionId,
        );
        foreach ($records as &$row) {
            $row['value'] = self::decode($row['value_json'], null);
            $row['evidence_ids'] = self::decode($row['evidence_ids_json'], []);
            $row['upstream_record_ids'] = self::decode($row['upstream_record_ids_json'], []);
            unset($row['value_json'], $row['evidence_ids_json'], $row['upstream_record_ids_json']);
        }

        $evidence = $this->rows(
            'SELECT evidence_id,evidence_type,title,source_reference,captured_at,metadata_json
             FROM diagnostic_evidence
             WHERE organization_id=:org AND session_id=:session
             ORDER BY captured_at,evidence_id',
            $organizationId,
            $sessionId,
        );
        foreach ($evidence as &$row) {
            $row['metadata'] = self::decode($row['metadata_json'], []);
            unset($row['metadata_json']);
        }

        $assessments = $this->rows(
            'SELECT criterion_id,score,coverage,confidence,applicable,updated_at
             FROM diagnostic_assessment_results
             WHERE organization_id=:org AND session_id=:session
             ORDER BY criterion_id',
            $organizationId,
            $sessionId,
        );

        $turns = $this->rows(
            'SELECT turn_id,question_id,status,extracted_fact_ids_json,evidence_ids_json,state_revision,created_at
             FROM diagnostic_interview_turns
             WHERE organization_id=:org AND diagnostic_id=:session
             ORDER BY created_at,turn_id',
            $organizationId,
            $sessionId,
        );
        foreach ($turns as &$row) {
            $row['extracted_fact_ids'] = self::decode($row['extracted_fact_ids_json'], []);
            $row['evidence_ids'] = self::decode($row['evidence_ids_json'], []);
            unset($row['extracted_fact_ids_json'], $row['evidence_ids_json']);
        }

        $contradictions = $this->rows(
            'SELECT contradiction_id,statement_a,statement_b,evidence_a_json,evidence_b_json,severity,status,required_clarification,created_at
             FROM diagnostic_contradictions
             WHERE organization_id=:org AND diagnostic_id=:session
             ORDER BY created_at,contradiction_id',
            $organizationId,
            $sessionId,
        );
        foreach ($contradictions as &$row) {
            $row['evidence_a'] = self::decode($row['evidence_a_json'], []);
            $row['evidence_b'] = self::decode($row['evidence_b_json'], []);
            unset($row['evidence_a_json'], $row['evidence_b_json']);
        }

        $aiAudit = $this->rows(
            'SELECT audit_id,operation,model,prompt_version,schema_version,tokens_input,tokens_output,estimated_cost,duration_ms,status,created_at
             FROM diagnostic_ai_audit
             WHERE organization_id=:org AND diagnostic_id=:session
             ORDER BY created_at,audit_id',
            $organizationId,
            $sessionId,
        );

        $counts = [];
        foreach ($records as $record) {
            $kind = (string) $record['record_type'];
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        return [
            'session' => $session,
            'summary' => [
                'record_counts' => $counts,
                'evidence_count' => count($evidence),
                'assessment_count' => count($assessments),
                'interview_turns' => count($turns),
                'contradictions' => count($contradictions),
                'ai_calls' => count($aiAudit),
            ],
            'methodology' => $methodology,
            'assessments' => $assessments,
            'records' => $records,
            'evidence' => $evidence,
            'interview_turns' => $turns,
            'contradictions' => $contradictions,
            'ai_audit' => $aiAudit,
        ];
    }

    public function permissionMatrix(string $organizationId): array
    {
        $statement = $this->database->prepare(
            'SELECT membership.user_id,membership.role,user.full_name,user.email
             FROM cos_organization_memberships membership
             JOIN tn_users user ON user.id=membership.user_id
             WHERE membership.organization_id=:org AND membership.status="ACTIVE"
             ORDER BY user.full_name,user.id'
        );
        $statement->execute(['org' => $organizationId]);
        $users = $statement->fetchAll(PDO::FETCH_ASSOC);

        $roleStatement = $this->database->query(
            'SELECT role,permission FROM diagnostic_role_permissions ORDER BY role,permission'
        );
        $rolePermissions = [];
        foreach ($roleStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rolePermissions[(string) $row['role']][(string) $row['permission']] = true;
        }

        $overrideStatement = $this->database->prepare(
            'SELECT user_id,permission,allowed,updated_at
             FROM diagnostic_user_permissions
             WHERE organization_id=:org'
        );
        $overrideStatement->execute(['org' => $organizationId]);
        $overrides = [];
        foreach ($overrideStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $overrides[(int) $row['user_id']][(string) $row['permission']] = $row;
        }

        foreach ($users as &$user) {
            $id = (int) $user['user_id'];
            $role = (string) $user['role'];
            $permissions = [];
            foreach (self::PERMISSIONS as $permission) {
                $inherited = isset($rolePermissions[$role][$permission]);
                $override = $overrides[$id][$permission] ?? null;
                $mode = $override === null ? 'inherit' : ((bool) $override['allowed'] ? 'allow' : 'deny');
                $permissions[$permission] = [
                    'inherited' => $inherited,
                    'mode' => $mode,
                    'effective' => $mode === 'inherit' ? $inherited : $mode === 'allow',
                    'updated_at' => $override['updated_at'] ?? null,
                ];
            }
            $user['permissions'] = $permissions;
        }

        $auditStatement = $this->database->prepare(
            'SELECT audit.audit_id,audit.actor_user_id,audit.target_user_id,
                    audit.permission,audit.old_mode,audit.new_mode,audit.created_at,
                    actor.full_name actor_name,target.full_name target_name
             FROM diagnostic_permission_audit audit
             LEFT JOIN tn_users actor ON actor.id=audit.actor_user_id
             LEFT JOIN tn_users target ON target.id=audit.target_user_id
             WHERE audit.organization_id=:org
             ORDER BY audit.created_at DESC
             LIMIT 50'
        );
        $auditStatement->execute(['org' => $organizationId]);

        return [
            'users' => $users,
            'audit' => $auditStatement->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function setPermissionOverride(
        string $organizationId,
        int $actorUserId,
        int $targetUserId,
        string $permission,
        string $mode,
    ): void {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            throw new DomainException('Unsupported methodology permission.');
        }
        if (!in_array($mode, ['inherit', 'allow', 'deny'], true)) {
            throw new DomainException('Permission mode must be inherit, allow or deny.');
        }

        $member = $this->database->prepare(
            'SELECT role
             FROM cos_organization_memberships
             WHERE organization_id=:org AND user_id=:user AND status="ACTIVE"
             LIMIT 1'
        );
        $member->execute(['org' => $organizationId, 'user' => $targetUserId]);
        $role = $member->fetchColumn();
        if ($role === false) {
            throw new DomainException('Target user is not an active organization member.');
        }

        if ($permission === 'diagnostic.methodology.publish' && $targetUserId === $actorUserId) {
            $inherited = $this->database->prepare(
                'SELECT 1 FROM diagnostic_role_permissions WHERE role=:role AND permission=:permission LIMIT 1'
            );
            $inherited->execute(['role' => (string) $role, 'permission' => $permission]);
            $inheritsPublish = $inherited->fetchColumn() !== false;
            $effectiveAfterChange = $mode === 'allow' || ($mode === 'inherit' && $inheritsPublish);
            if (!$effectiveAfterChange) {
                throw new DomainException('You cannot remove your own methodology publish access. Delegate publish access first.');
            }
        }

        $old = $this->database->prepare(
            'SELECT allowed
             FROM diagnostic_user_permissions
             WHERE organization_id=:org AND user_id=:user AND permission=:permission'
        );
        $old->execute(['org' => $organizationId, 'user' => $targetUserId, 'permission' => $permission]);
        $oldAllowed = $old->fetchColumn();
        $oldMode = $oldAllowed === false ? 'inherit' : ((bool) $oldAllowed ? 'allow' : 'deny');

        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            if ($mode === 'inherit') {
                $statement = $this->database->prepare(
                    'DELETE FROM diagnostic_user_permissions
                     WHERE organization_id=:org AND user_id=:user AND permission=:permission'
                );
                $statement->execute([
                    'org' => $organizationId,
                    'user' => $targetUserId,
                    'permission' => $permission,
                ]);
            } else {
                $statement = $this->database->prepare(
                    'INSERT INTO diagnostic_user_permissions(organization_id,user_id,permission,allowed)
                     VALUES(:org,:user,:permission,:allowed)
                     ON DUPLICATE KEY UPDATE allowed=VALUES(allowed),updated_at=CURRENT_TIMESTAMP(6)'
                );
                $statement->execute([
                    'org' => $organizationId,
                    'user' => $targetUserId,
                    'permission' => $permission,
                    'allowed' => $mode === 'allow' ? 1 : 0,
                ]);
            }

            $audit = $this->database->prepare(
                'INSERT INTO diagnostic_permission_audit(
                    organization_id,audit_id,actor_user_id,target_user_id,permission,old_mode,new_mode,created_at
                 ) VALUES(:org,:id,:actor,:target,:permission,:old,:new,CURRENT_TIMESTAMP(6))'
            );
            $audit->execute([
                'org' => $organizationId,
                'id' => bin2hex(random_bytes(16)),
                'actor' => $actorUserId,
                'target' => $targetUserId,
                'permission' => $permission,
                'old' => $oldMode,
                'new' => $mode,
            ]);

            if ($ownsTransaction) {
                $this->database->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, string $organizationId, string $sessionId): array
    {
        $statement = $this->database->prepare($sql);
        $statement->execute(['org' => $organizationId, 'session' => $sessionId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function decode(?string $json, mixed $fallback): mixed
    {
        if ($json === null || $json === '') {
            return $fallback;
        }
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $fallback;
        }
    }
}
