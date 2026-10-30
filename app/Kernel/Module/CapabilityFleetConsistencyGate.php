<?php
declare(strict_types=1);

namespace Kernel\Module;

use DomainException;

/**
 * Offline consistency verification of operator-collected container witnesses.
 *
 * This is NOT cryptographic provenance, production connectivity, a deployment
 * attestation, tenant authorization, or permission to enable an Action worker.
 */
final readonly class CapabilityFleetConsistencyGate
{
    /**
     * @param array<string,mixed> $manifest
     * @param list<array<string,mixed>> $witnesses
     * @return array<string,mixed>
     */
    public function inspect(array $manifest, array $witnesses): array
    {
        $errors=[];
        $sha=$manifest['release_sha'] ?? null;
        if (($manifest['format'] ?? null)!=='cos-capability-fleet-manifest-v1'
            || !is_string($sha) || preg_match('/^[a-f0-9]{40}$/D',$sha)!==1) {
            throw new DomainException('Fleet manifest requires an exact 40-character release SHA.');
        }
        $expected=$manifest['expected_instances'] ?? null;
        if (!is_array($expected) || !array_is_list($expected) || count($expected)<2
            || count($expected)>100) {
            throw new DomainException('Fleet manifest requires two to one hundred exact instances.');
        }
        $ids=[];
        $roles=[];
        foreach ($expected as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)
                || preg_match('/^[a-zA-Z0-9._-]{1,128}$/D',$item['id'])!==1
                || !in_array($item['role'] ?? null,['web','worker'],true)
                || isset($ids[$item['id']])) {
                throw new DomainException('Fleet manifest has invalid or duplicate instance identity.');
            }
            $ids[$item['id']]=$item['role'];
            $roles[$item['role']]=true;
        }
        if (!isset($roles['web'],$roles['worker'])) {
            throw new DomainException('Fleet acceptance requires explicit web and worker instances.');
        }
        ksort($ids,SORT_STRING);
        if (!array_is_list($witnesses) || count($witnesses)>100) {
            throw new DomainException('Fleet witnesses must be a bounded list.');
        }
        $actual=[];
        $baseline=null;
        $digests=[];
        foreach ($witnesses as $witness) {
            if (!is_array($witness)) {
                $errors[]='MALFORMED_WITNESS';
                continue;
            }
            $id=$witness['instance_id'] ?? null;
            if (!is_string($id) || !isset($ids[$id])) {
                $errors[]='UNKNOWN_INSTANCE';
                continue;
            }
            if (isset($actual[$id])) {
                $errors[]='DUPLICATE_INSTANCE:'.$id;
                continue;
            }
            $actual[$id]=true;
            if (($witness['format'] ?? null)!=='cos-capability-runtime-instance-witness-v1'
                || ($witness['scope'] ?? null)!=='CURRENT_SYMFONY_CONTAINER_ONLY'
                || ($witness['deployment_sha'] ?? null)!==$sha
                || ($witness['instance_role'] ?? null)!==$ids[$id]) {
                $errors[]='IDENTITY_SHA_OR_SCOPE_MISMATCH:'.$id;
            }
            if (($witness['worker_admission_mode'] ?? null)!=='WORKER_DISABLED_FAIL_CLOSED'
                || ($witness['worker_execution_certified'] ?? null)!==false) {
                $errors[]='WORKFLOW_GATE_NOT_FAIL_CLOSED:'.$id;
            }
            if (($witness['tenant_policies_checked'] ?? null)!==false
                || ($witness['approval_receipts_checked'] ?? null)!==false
                || ($witness['native_business_outcomes_checked'] ?? null)!==false) {
                $errors[]='UNSUPPORTED_CERTIFICATION_CLAIM:'.$id;
            }
            if (($witness['action_registration_issues'] ?? null)!==0) {
                $errors[]='ACTION_REGISTRATION_ISSUE:'.$id;
            }
            $rows=$witness['capabilities'] ?? null;
            if (!is_array($rows) || !array_is_list($rows)
                || count($rows)<1 || count($rows)>2000) {
                $errors[]='INVALID_CAPABILITY_CENSUS:'.$id;
                continue;
            }
            if (($witness['typed_contracts'] ?? null)!==count($rows)) {
                $errors[]='CONTRACT_COUNT_MISMATCH:'.$id;
            }
            $fingerprints=[];
            foreach ($rows as $row) {
                if (!is_array($row)
                    || !is_string($row['id'] ?? null) || ($row['id'] ?? '')===''
                    || !is_string($row['owner_domain'] ?? null)
                    || !is_string($row['version'] ?? null)
                    || !is_string($row['contract_digest'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D',$row['contract_digest'])!==1
                    || isset($fingerprints[$row['id']])) {
                    $errors[]='INVALID_OR_DUPLICATE_CONTRACT:'.$id;
                    continue;
                }
                if (($row['binding_family'] ?? null)==='action'
                    && ($row['witness'] ?? null)!=='ACTION_HANDLER_REGISTERED') {
                    $errors[]='ACTION_HANDLER_NOT_REGISTERED:'.$id.':'.$row['id'];
                }
                $fingerprints[$row['id']]=[
                    $row['owner_domain'],$row['version'],
                    $row['contract_digest'],$row['binding_family'] ?? null,
                ];
            }
            ksort($fingerprints,SORT_STRING);
            if ($baseline===null) {
                $baseline=$fingerprints;
            } elseif ($baseline!==$fingerprints) {
                $errors[]='CROSS_REPLICA_CONTRACT_DRIFT:'.$id;
            }
            $digests[$id]=hash('sha256',json_encode($fingerprints,JSON_THROW_ON_ERROR));
        }
        foreach (array_keys($ids) as $id) {
            if (!isset($actual[$id])) $errors[]='MISSING_INSTANCE:'.$id;
        }
        sort($errors,SORT_STRING);
        ksort($digests,SORT_STRING);
        return [
            'format'=>'cos-capability-fleet-consistency-result-v1',
            'release_sha'=>$sha,
            'expected_instance_count'=>count($ids),
            'received_instance_count'=>count($actual),
            'status'=>$errors===[] ? 'CONSISTENT_UNCERTIFIED' : 'FAIL_CLOSED',
            'consistent'=>$errors===[],
            'production_certified'=>false,
            'operator_sign_off_required'=>true,
            'deployment_provenance_verified'=>false,
            'tenant_permissions_verified'=>false,
            'human_approval_and_receipts_verified'=>false,
            'native_business_evidence_verified'=>false,
            'contract_set_hashes'=>$digests,
            'errors'=>$errors,
        ];
    }
}
