<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Two independent gates: active tenant admin membership AND a short-lived,
 * tenant/run/source-bound RSA signature issued by a separate trusted authority.
 * Never accepts a caller-supplied --operator string as authentication.
 *
 * The permit is "v2:<expiry_unix>:<hex_RSA_SHA256_signature>". The
 * executing server only knows the public key, NEVER the signing private key. Permit use is fenced by the
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
        $publicKeyBase64=getenv('COS_FEDERATION_MIGRATION_AUTHORITY_PUBLIC_KEY_BASE64');
        if (!is_string($publicKeyBase64)
            || !preg_match('/^v2:([0-9]{10,11}):([a-f0-9]{512,1024})$/',$permit,$parts)) {
            throw new DomainException('Migration requires a separately signed RSA permit.');
        }
        $expiry=(int)$parts[1];
        $now=time();
        if ($expiry<=$now || $expiry>$now+900) {
            throw new DomainException('Historical migration permit is expired or exceeds the allowed 15-minute window.');
        }
        $pem=base64_decode($publicKeyBase64,true);
        $key=is_string($pem)?openssl_pkey_get_public($pem):false;
        if ($key===false) {
            throw new DomainException('Migration authority public key is unavailable.');
        }
        $details=openssl_pkey_get_details($key);
        if (!is_array($details)||($details['type']??null)!==OPENSSL_KEYTYPE_RSA
            || ($details['bits']??0)<2048) {
            throw new DomainException('Migration authority must use an RSA key of at least 2048 bits.');
        }
        $payload=implode("\n",['federation-cutover-v2',$organizationId,$runId,$operatorId,$sourceSha256,(string)$expiry]);
        $signature=hex2bin($parts[2]);
        if ($signature===false || openssl_verify($payload,$signature,$key,OPENSSL_ALGO_SHA256)!==1) {
            throw new DomainException('Historical migration permit is not signed for this tenant, Run, operator and source.');
        }
        return hash('sha256',$permit);
    }
}
