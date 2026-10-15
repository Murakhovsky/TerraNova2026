<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Throwable;

/**
 * Operator-only, tenant-scoped, bounded and READ-ONLY fleet inventory.
 * It cannot attest a release, run historical migration or replay Actions.
 * Unenumerated history and unverifiable migration links always prevent
 * a claim of complete coverage. Never returns an accepted/ready status.
 */
final readonly class FederationExecutionFleetAudit
{
    public function __construct(
        private Connection $db,
        private FederationHistoricalRunCutover $historical,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(string $organizationId, int $limit=100): array
    {
        if (trim($organizationId)==='' || strlen($organizationId)>190) {
            throw new DomainException('Federation fleet audit requires one valid tenant.');
        }
        $limit=max(1,min(100,$limit));
        $params=['org'=>$organizationId];
        $legacyTotal=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_federation_runs WHERE organization_id=:org',$params);
        $canonicalTotal=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_workflow_runs
             WHERE organization_id=:org AND definition_id="federation.approved-plan"',$params);
        $migrationTotal=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_federation_execution_migrations
             WHERE organization_id=:org',$params);

        // Fixed tenant filter and deterministic bounded order: no accidental
        // cross-tenant enumeration or full-table result materialization.
        $legacy=$this->db->fetchAllAssociative(
            'SELECT run_id,plan_id,state FROM cos_federation_runs
             WHERE organization_id=:org ORDER BY run_id LIMIT '.$limit,$params);
        $canonical=$this->db->fetchAllAssociative(
            'SELECT w.run_id,w.source_plan_id,w.state,p.plan_id AS plan_exists
             FROM cos_workflow_runs w
             LEFT JOIN cos_federation_plans p
               ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
             WHERE w.organization_id=:org AND w.definition_id="federation.approved-plan"
             ORDER BY w.run_id LIMIT '.$limit,$params);
        $migrations=$this->db->fetchAllAssociative(
            'SELECT legacy_run_id,canonical_run_id,migration_state,parity_status
             FROM cos_federation_execution_migrations
             WHERE organization_id=:org ORDER BY legacy_run_id LIMIT '.$limit,$params);

        $truncated=$legacyTotal>count($legacy)
            || $canonicalTotal>count($canonical)
            || $migrationTotal>count($migrations);
        $legacyById=[];$byPlan=[];$canonicalById=[];$canonicalByPlan=[];$links=[];
        foreach($legacy as $row) {
            $id=(string)$row['run_id'];$plan=(string)$row['plan_id'];
            $legacyById[$id]=$row;
            $byPlan[$plan][]=$id;
        }
        foreach($canonical as $row) {
            $id=(string)$row['run_id'];
            $canonicalById[$id]=$row;
            if($row['source_plan_id']!==null) {
                $canonicalByPlan[(string)$row['source_plan_id']][]=$id;
            }
        }
        foreach($migrations as $row) $links[(string)$row['legacy_run_id']]=$row;
        $findings=[];$runs=[];
        foreach($legacy as $row) {
            $id=(string)$row['run_id'];$plan=(string)$row['plan_id'];
            $migration=$links[$id]??null;
            $issues=[];$migrationState=(string)($migration['migration_state']??'not_migrated');
            if($migration!==null) {
                if($migrationState!=='promoted'
                    || (string)$migration['canonical_run_id']!==$id
                    || ($migration['parity_status']??null)!=='match'
                    || !isset($canonicalById[$id])) {
                    $issues[]='untrusted_or_incomplete_migration_link';
                }
            }
            foreach($canonicalByPlan[$plan]??[] as $canonicalId) {
                if($migration===null || $migrationState!=='promoted'
                    || (string)$migration['canonical_run_id']!==$canonicalId
                    || $canonicalId!==$id) {
                    $issues[]='unmapped_dual_execution_authority';
                }
            }
            try {
                $audit=$this->historical->inspect($organizationId,$id);
                if($migration!==null) {
                    $post=$audit['post_cutover_integrity']['status']??null;
                    if($post!=='healthy') {
                        $issues[]=$post==='reconciliation_required'
                            ? 'post_cutover_reconciliation_required'
                            : 'post_cutover_integrity_drift';
                    }
                    if(($audit['parity']??null)!=='match') {
                        $issues[]='historical_cutover_parity_not_attested';
                    }
                } elseif(($audit['promotable']??false)!==true) {
                    $issues[]='legacy_run_requires_operator_reconciliation';
                }
            } catch(Throwable) {
                $issues[]='historical_run_integrity_unreadable';
            }
            $issues=array_values(array_unique($issues));
            foreach($issues as $issue) $findings[$issue]=true;
            $runs[]=[
                'run_id'=>$id,'plan_id'=>$plan,'legacy_state'=>(string)$row['state'],
                'migration_state'=>$migrationState,
                'status'=>$issues===[]?($migration===null
                    ? 'unmigrated_review_pending':'promoted_integrity_healthy_not_release'):(
                    in_array('legacy_run_requires_operator_reconciliation',$issues,true)
                    || in_array('post_cutover_reconciliation_required',$issues,true)
                        ? 'reconciliation_required':'drift'),
                'findings'=>$issues,
            ];
        }

        foreach($canonical as $row) {
            $id=(string)$row['run_id'];
            $plan=$row['source_plan_id']===null?'':(string)$row['source_plan_id'];
            if($plan===''||$row['plan_exists']===null) {
                $findings['orphaned_canonical_plan']=true;
            }
            foreach($byPlan[$plan]??[] as $oldId) {
                $link=$links[$oldId]??null;
                if(!$link || ($link['migration_state']??null)!=='promoted'
                    || (string)$link['canonical_run_id']!==$id || $oldId!==$id) {
                    $findings['unmapped_dual_execution_authority']=true;
                }
            }
        }
        foreach($migrations as $link) {
            if(!isset($legacyById[(string)$link['legacy_run_id']])
                || !isset($canonicalById[(string)$link['canonical_run_id']])) {
                $findings['migration_link_outside_audited_cohort']=true;
            }
        }
        foreach($byPlan as $ids) if(count($ids)>1) $findings['duplicate_legacy_plan_runs']=true;
        foreach($canonicalByPlan as $ids) if(count($ids)>1) $findings['duplicate_canonical_plan_runs']=true;
        if($truncated) $findings['fleet_inventory_truncated']=true;
        if($legacyTotal===0 && $canonicalTotal===0) $findings['no_federation_run_evidence']=true;

        $issues=array_keys($findings);
        $critical=array_filter($issues,static fn(string $x):bool=>
            in_array($x,[
                'untrusted_or_incomplete_migration_link',
                'unmapped_dual_execution_authority',
                'post_cutover_integrity_drift',
                'historical_cutover_parity_not_attested',
                'historical_run_integrity_unreadable',
                'orphaned_canonical_plan',
                'duplicate_legacy_plan_runs',
                'duplicate_canonical_plan_runs',
            ],true));
        $status=$critical!==[]?'drift':($truncated||$legacyTotal===0&&$canonicalTotal===0
            ?'no_target':'partial');
        return [
            'source'=>'federation_fleet','organization_id'=>$organizationId,
            'status'=>$status,'findings'=>$issues,'historical_runs'=>$runs,
            'legacy_total'=>$legacyTotal,'canonical_total'=>$canonicalTotal,
            'migration_total'=>$migrationTotal,
            'evaluated_legacy_runs'=>count($legacy),
            'evaluated_canonical_runs'=>count($canonical),
            'evaluated_migration_links'=>count($migrations),
            'history_truncated'=>$truncated,'inventory_coverage_complete'=>!$truncated,
            'read_only'=>true,'cutover_authorized'=>false,
            'business_outcome_authority'=>'domain_owned',
        ];
    }
}
