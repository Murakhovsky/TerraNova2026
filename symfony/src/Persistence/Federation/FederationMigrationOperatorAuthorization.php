<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Two independent gates: active tenant admin membership AND a short-lived,
 * tenant/run/source-bound HMAC permit issued by a separately controlled authority.
 * Never accepts a caller-supplied --operator string as authentication.
 *
 * The permit is "v1:<expiry_unix>:<lowercase_hex_sha256_hmac>". Do not expose
 * the signing key to the untrusted CLI invoker. Permit use is fenced by the
 * migration ledger's unique organization/run and organization/plan constraints.
 */
final readonly class FederationMigrationOperatorAuthorization
{
    public function __construct(private Connection $db) {}

    public function assertAuthorized(
        string $organizationId,
        string $runId,
        string $operatorId,
        string $sourceSha256,
        string $permit,
    ): string {
        if (getenv('COS_FEDERATION_HISTORICAL_MIGRATION') !== 'explicit_operator_approved') {
            throw new DomainException('Federation migration is disabled without an explicit rollout gate.');
        }
        if (!preg_match('/^[1-9][0-9]{0,18}$/', $operatorId)
            || !preg_match('/^[a-f0-9]{64}$/', $sourceSha256)) {
            throw new DomainException('Migration requires a real tenant operator ID and immutable source digest.');
        }
        $row=$this->db->fetchAssociative(
            "SELECT u.id, u.status AS user_status, m.status AS member_status, m.role AS member_role
             FROM tn_users u
             INNER JOIN cos_organization_memberships m
               ON m.organization_id=u.organization_id AND m.user_id=u.id
             WHERE u.id=:id AND u.organization_id=:org",
            ['id'=>$operatorId,'org'=>$organizationId],
        );
        if (!$row || strtolower((string)$row['user_status'])!=='active'
            || $row['member_status']!=='ACTIVE'
            || !in_array(strtolower((string)$row['member_role']),['admin','owner'],true)) {
            throw new DomainException('Historical migration operator lacks active tenant admin authorization.');
        }
        $secret=getenv('COS_FEDERATION_MIGRATION_AUTHORITY_KEY');
        if (!is_string($secret) || strlen($secret)<32
            || !preg_match('/^v1:([0-9]{10,11}):([a-f0-9]{64})$/',$permit,$parts)) {
            throw new DomainException('Migration requires a separately authorized, time-bound signed permit.');
        }
        $expiry=(int)$parts[1];
        $now=time();
        if ($expiry<=$now || $expiry>$now+900) {
            throw new DomainException('Historical migration permit is expired or exceeds the allowed 15-minute window.');
        }
        $payload=implode("\n",['federation-cutover-v1',$organizationId,$runId,$operatorId,$sourceSha256,(string)$expiry]);
        if (!hash_equals(hash_hmac('sha256',$payload,$secret),$parts[2])) {
            throw new DomainException('Historical migration permit does not match signed tenant, operator, Run and source.');
        }
        return hash('sha256',$permit);
    }
}
