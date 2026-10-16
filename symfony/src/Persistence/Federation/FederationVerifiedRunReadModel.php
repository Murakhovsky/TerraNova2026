<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Read-only evidence adapter, NEVER an execution or reconciliation scheduler.
 * Existing historical rows are inspected without creating a second writer.
 * Canonical receipts are attested by the independent Platform verifier.
 */
final readonly class FederationVerifiedRunReadModel
{
    public function __construct(
        private Connection $db,
        private DoctrineWorkflowActionReceiptVerifier $canonical,
        private FederationHistoricalRunCutover $migrations,
        private ?FederationExternalActionReceiptReconciler $historical = null,
    ) {}

    /** @return array{run_id:string,run_state:string,authority:string}|null */
    public function forPlan(TenantContext $actor,string $planId): ?array
    {
        $this->manager($actor);
        self::id($planId);
        $org=$actor->organizationId()->value();
        $legacy=$this->db->fetchAllAssociative(
            'SELECT run_id,state FROM cos_federation_runs
             WHERE organization_id=:org AND plan_id=:plan LIMIT 2',
            ['org'=>$org,'plan'=>$planId],
        );
        $canonical=$this->db->fetchAllAssociative(
            'SELECT run_id,state,definition_id FROM cos_workflow_runs
             WHERE organization_id=:org AND source_plan_id=:plan LIMIT 2',
            ['org'=>$org,'plan'=>$planId],
        );
        if (count($legacy)>1 || count($canonical)>1) {
            throw new DomainException('Plan has duplicate execution authorities; operator reconciliation required.');
        }
        if ($legacy!==[] && $canonical!==[]) {
            if ($legacy[0]['run_id']!==$canonical[0]['run_id']) {
                throw new DomainException('Plan has unrelated historical and canonical Runs.');
            }
            $this->requireVerifiedMigration($org,$planId,(string)$legacy[0]['run_id']);
        }
        if ($canonical!==[]) {
            if ($canonical[0]['definition_id']!=='federation.approved-plan') {
                throw new DomainException('Plan has unrelated canonical execution authority.');
            }
            return ['run_id'=>(string)$canonical[0]['run_id'],
                'run_state'=>(string)$canonical[0]['state'],'authority'=>'canonical'];
        }
        return $legacy!==[]?['run_id'=>(string)$legacy[0]['run_id'],
            'run_state'=>(string)$legacy[0]['state'],'authority'=>'historical']:null;
    }

    /** @return array<string,mixed>|null */
    public function step(TenantContext $actor,string $runId,string $stepId): ?array
    {
        $this->manager($actor);
        self::id($runId);
        self::id($stepId);
        $org=$actor->organizationId()->value();
        $legacy=$this->db->fetchAssociative(
            'SELECT p.goal_id,p.state AS plan_state,p.spec_version,
                    r.plan_id,r.state AS run_state,s.state AS step_state,
                    s.capability_id,s.side_effect_level,s.idempotency_key,s.result_reference
             FROM cos_federation_runs r
             JOIN cos_federation_plans p
               ON p.organization_id=r.organization_id AND p.plan_id=r.plan_id
             JOIN cos_federation_steps s
               ON s.organization_id=r.organization_id AND s.run_id=r.run_id
             WHERE r.organization_id=:org AND r.run_id=:run AND s.step_id=:step',
            ['org'=>$org,'run'=>$runId,'step'=>$stepId],
        );
        $canonical=$this->db->fetchAssociative(
            'SELECT p.goal_id,p.state AS plan_state,p.spec_version,
                    w.source_plan_id AS plan_id,w.state AS run_state,w.definition_id,
                    w.input_digest,s.state AS step_state,s.capability_id,
                    s.side_effect_level,s.idempotency_key,s.result_reference
             FROM cos_workflow_runs w
             JOIN cos_federation_plans p
               ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
             JOIN cos_workflow_steps s
               ON s.organization_id=w.organization_id AND s.run_id=w.run_id
             WHERE w.organization_id=:org AND w.run_id=:run AND s.step_id=:step',
            ['org'=>$org,'run'=>$runId,'step'=>$stepId],
        );
        if ($canonical!==false && $legacy!==false
            && $canonical['plan_id']!==$legacy['plan_id']) {
            throw new DomainException('Run has mismatched historical and canonical Plan identity.');
        }
        $selected=$canonical!==false?$canonical:$legacy;
        if ($selected!==false) {
            $owner=$this->forPlan($actor,(string)$selected['plan_id']);
            if ($owner===null || $owner['run_id']!==$runId
                || $owner['authority']!==($canonical!==false?'canonical':'historical')) {
                throw new DomainException('Step is not owned by the verified Plan execution authority.');
            }
        }
        if ($canonical!==false) {
            if ($canonical['definition_id']!=='federation.approved-plan') {
                throw new DomainException('Canonical Run is not an approved Federation Goal execution.');
            }
            $canonical['authority']='canonical';
            return $canonical;
        }
        if ($legacy!==false) {
            $legacy['authority']='historical';
            return $legacy;
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function stepsForRun(TenantContext $actor,string $runId,string $authority): array
    {
        $this->manager($actor);
        self::id($runId);
        if (!in_array($authority,['canonical','historical'],true)) {
            throw new DomainException('Unsupported execution authority.');
        }
        $org=$actor->organizationId()->value();
        $table=$authority==='canonical'?'cos_workflow_steps':'cos_federation_steps';
        return $this->db->fetchAllAssociative(
            'SELECT step_id,capability_id,state FROM '.$table.'
             WHERE organization_id=:org AND run_id=:run LIMIT 51',
            ['org'=>$org,'run'=>$runId],
        );
    }

    /**
     * Re-attest the completed Action; do not acknowledge or mutate a Step.
     * Never call the legacy reconciliation branch for a claimed Step.
     */
    public function requireCompletedAction(
        TenantContext $actor,string $runId,string $stepId,
    ): string {
        $source=$this->step($actor,$runId,$stepId);
        if ($source===null || $source['run_state']!=='completed'
            || $source['step_state']!=='completed'
            || $source['plan_state']!=='approved'
            || $source['side_effect_level']!=='external'
            || !is_string($source['result_reference']??null)
            || !preg_match('/^action:([a-f0-9]{32})$/D',$source['result_reference'],$match)) {
            throw new DomainException('Completed Action has no trustworthy tenant-owned Step receipt.');
        }
        if ($source['authority']==='canonical') {
            $inputDigest=$source['input_digest']??null;
            if (!is_string($inputDigest) || !preg_match('/^[a-f0-9]{64}$/D',$inputDigest)) {
                throw new DomainException('Canonical Action receipt lacks pinned Workflow input digest.');
            }
            $attestation=$this->canonical->attest(new WorkflowReceiptRequest(
                new WorkflowRunIdentity($actor->organizationId()->value(),$runId),
                $actor,$stepId,$source['result_reference'],$inputDigest,
            ));
            if (!$attestation->verified || $attestation->evidenceReference!==$source['result_reference']) {
                throw new DomainException('Independent canonical Action receipt attestation failed.');
            }
        } else {
            if ($this->historical===null) {
                throw new DomainException('Historical Action attestor is not installed.');
            }
            $receipt=$this->historical->reconcile($actor,$runId,$stepId);
            if (($receipt['status']??null)!=='completed'
                || ($receipt['action_id']??null)!==$match[1]) {
                throw new DomainException('Historical Action receipt failed independent re-attestation.');
            }
        }
        return $match[1];
    }

    /** @param array<string,mixed> $source */
    public function actionKey(array $source): string
    {
        $id=$source['idempotency_key']??null;
        $authority=$source['authority']??null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D',$id)
            || !in_array($authority,['canonical','historical'],true)) {
            throw new DomainException('Action idempotency provenance is missing.');
        }
        return ($authority==='canonical'?'wf:':'fed:').$id;
    }

    /**
     * Historical rows intentionally remain frozen after a legitimate cutover.
     * Trust only the signed, audited migration ledger AND fresh independent
     * post-cutover integrity; matching identifiers or a status flag alone do
     * not authorize an overlapping execution projection.
     */
    private function requireVerifiedMigration(string $org,string $planId,string $runId): void
    {
        $audit=$this->migrations->inspect($org,$runId);
        if (($audit['migration_state']??null)!=='promoted'
            || ($audit['cutover_parity']??null)!=='match'
            || ($audit['post_cutover_integrity']['status']??null)!=='healthy'
            || in_array('legacy_source_mutated_after_cutover',$audit['reasons']??[],true)) {
            throw new DomainException('Historical/canonical overlap lacks independently verified migration.');
        }
        // The ledger includes the frozen one-to-one Plan mapping; both
        // the approved legacy and canonical rows must point to it.
        $ledger=$this->db->fetchAssociative(
            'SELECT plan_id,canonical_run_id,source_sha256,
                    canonical_identity_sha256,permit_sha256,operator_id
             FROM cos_federation_execution_migrations
             WHERE organization_id=:org AND legacy_run_id=:run
               AND migration_state=:state AND parity_status=:parity',
            ['org'=>$org,'run'=>$runId,'state'=>'promoted','parity'=>'match'],
        );
        if (!$ledger || $ledger['plan_id']!==$planId
            || $ledger['canonical_run_id']!==$runId
            || !is_string($ledger['source_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D',$ledger['source_sha256'])
            || !is_string($ledger['canonical_identity_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D',$ledger['canonical_identity_sha256'])
            || !is_string($ledger['permit_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D',$ledger['permit_sha256'])
            || !is_string($ledger['operator_id']) || $ledger['operator_id']==='') {
            throw new DomainException('Immutable historical cutover identity or permit evidence is unavailable.');
        }
        $event=$this->db->fetchAssociative(
            'SELECT source_sha256,canonical_identity_sha256,permit_sha256,operator_id
             FROM cos_federation_migration_audit
             WHERE organization_id=:org AND legacy_run_id=:run AND event_code=:event',
            ['org'=>$org,'run'=>$runId,'event'=>'cutover_accepted'],
        );
        if (!$event
            || $event['source_sha256']!==$ledger['source_sha256']
            || $event['canonical_identity_sha256']!==$ledger['canonical_identity_sha256']
            || $event['permit_sha256']!==$ledger['permit_sha256']
            || $event['operator_id']!==$ledger['operator_id']) {
            throw new DomainException('Canonical cutover has no matching independent accepted operator audit.');
        }
    }

    private function manager(TenantContext $actor): void
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Read-only Run evidence requires authenticated tenant manager.');
        }
    }

    private static function id(string $value): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$value)) {
            throw new DomainException('Invalid Run evidence identifier.');
        }
    }
}
