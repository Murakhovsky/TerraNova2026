<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Growth\Application\Contract\GrowthHandoffBoundary;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Documents\Contract\DocumentsRepositoryInterface;
use Platform\Orchestration\Goal\FederationGoalAggregateProjector;
use Platform\Orchestration\Goal\GoalOutcomeEvaluator;

/**
 * Tenant-owned, read-only Goal-wide native artifact verifier. This is not a
 * substitute for a signed / persisted Goal business outcome evaluation.
 *
 * Only completed, approved Federation Action receipts are re-attested.
 * Then Growth's accepted handoff, Sales' Lead and Documents' relation are
 * checked through their owning read boundaries. No Action is dispatched.
 */
final readonly class FederationGoalOutcomeAggregationService
{
    private const ACTIONS = [
        'qualify' => 'growth.candidate.qualify',
        'prepare' => 'growth.handoff.prepare',
        'handoff' => 'growth.handoff.target.sales',
        'proposal' => 'documents.proposal.prepare',
    ];

    public function __construct(
        private Connection $db,
        private FederationGoalStore $goals,
        private ActiveModuleResolver $modules,
        private FederationExternalActionReceiptReconciler $receipts,
        private GrowthHandoffBoundary $growth,
        private SalesProposalLeadReadModelInterface $sales,
        private DocumentsRepositoryInterface $documents,
        private FederationGoalAggregateProjector $projector,
        private GoalOutcomeEvaluator $evaluator,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(TenantContext $actor, string $goalId): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$goalId)) {
            throw new DomainException('Goal aggregate requires an authenticated tenant manager.');
        }
        $goal = $this->goals->specification($actor,$goalId);
        if ($goal === null) {
            throw new DomainException('Goal does not exist in this tenant.');
        }
        $org=$actor->organizationId()->value();
        foreach (['federation','growth','sales','documents'] as $module) {
            if (!$this->modules->isEnabled($org,$module)) {
                throw new DomainException('Goal native outcome verification requires active tenant Modules.');
            }
        }
        $rows=$this->db->fetchAllAssociative(
            'SELECT p.plan_id,p.state AS plan_state,p.spec_version,p.plan_json,
                    r.run_id,r.state AS run_state
             FROM cos_federation_plans p
             LEFT JOIN cos_federation_runs r
               ON r.organization_id=p.organization_id AND r.plan_id=p.plan_id
             WHERE p.organization_id=:org AND p.goal_id=:goal
             ORDER BY p.plan_version,p.plan_id LIMIT 1025',
            ['org'=>$org,'goal'=>$goalId],
        );
        if (count($rows)>1024) {
            throw new DomainException('Goal has more Plans than the bounded read-only projection supports.');
        }
        $subjects=[];
        foreach ($rows as $row) {
            $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
            if (!is_array($plan) || array_is_list($plan)) {
                throw new DomainException('Goal contains malformed immutable Plan JSON.');
            }
            $lineage=$plan['lineage']??null;
            if ($lineage===null) continue; // Parent Discovery is not a candidate.
            if (!is_array($lineage) || ($lineage['schema_version']??null)!==1
                || !is_string($lineage['candidate_id']??null)
                || !is_string($lineage['account_id']??null)
                || !is_string($lineage['evidence_hash']??null)
                || !preg_match('/^[a-f0-9]{64}$/D',$lineage['evidence_hash'])
                || (int)$row['spec_version']!==$goal->version) {
                throw new DomainException('Goal candidate lineage or specification is stale or malformed.');
            }
            // MySQL JSON columns normalize object key order. A stored
            // object cannot be hashed by iteration order: reconstruct exactly
            // the v1 canonical producer field order before hashing.
            $proof=[];
            foreach (['schema_version','source_federation_run',
                      'source_federation_step','native_discovery_run',
                      'candidate_id','account_id','source_hash'] as $field) {
                if (!array_key_exists($field,$lineage)) {
                    throw new DomainException('Candidate Plan lineage has missing source identity.');
                }
                $proof[$field]=$lineage[$field];
            }
            if (count($lineage)!==8) {
                throw new DomainException('Candidate Plan lineage contains unexpected source fields.');
            }
            $fingerprint=hash('sha256',json_encode(
                $proof,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
            ));
            if (!hash_equals($lineage['evidence_hash'],$fingerprint)) {
                throw new DomainException('Candidate Plan lineage was modified after approval.');
            }
            $candidate=$lineage['candidate_id'];
            $steps=$plan['steps']??null;
            if (!is_array($steps) || count($steps)!==4 || !array_is_list($steps)) {
                throw new DomainException('Candidate Plan is not the canonical four-step workflow.');
            }
            $names=array_keys(self::ACTIONS);
            foreach ($names as $i=>$name) {
                $step=$steps[$i];
                $requires=$i===0?[]:[$names[$i-1]];
                if (!is_array($step)
                    || ($step['id']??null)!==$name
                    || ($step['capability_id']??null)!==self::ACTIONS[$name]
                    || ($step['capability_version']??null)!=='1.0.0'
                    || ($step['depends_on']??null)!==$requires
                    || ($step['side_effect_level']??null)!=='external'
                    || ($step['input']['target_type']??null)!=='growth_candidate'
                    || ($step['input']['target_id']??null)!==$candidate) {
                    throw new DomainException('Candidate Plan Action topology changed after approval.');
                }
            }
            if (($steps[3]['input']['parameters']['variables']['account_id']??null)
                !==$lineage['account_id']) {
                throw new DomainException('Document account does not match immutable Growth provenance.');
            }
            $runId=$row['run_id']===null?null:(string)$row['run_id'];
            $runState=$row['run_state']===null?null:(string)$row['run_state'];
            $actions=array_fill_keys($names,false);
            $ids=[];
            if ($row['plan_state']==='approved' && $runId!==null && $runState==='completed') {
                $actualSteps=$this->db->fetchAllAssociative(
                    'SELECT step_id,capability_id,state FROM cos_federation_steps
                     WHERE organization_id=:org AND run_id=:run',
                    ['org'=>$org,'run'=>$runId],
                );
                if (count($actualSteps)!==4) {
                    throw new DomainException('Finalized Candidate Run contains a mismatched Step set.');
                }
                foreach ($actualSteps as $step) {
                    $name=(string)$step['step_id'];
                    if (!isset(self::ACTIONS[$name])
                        || $step['capability_id']!==self::ACTIONS[$name]
                        || $step['state']!=='completed'
                        || isset($ids[$name])) {
                        throw new DomainException('Finalized Candidate Run has incomplete or duplicated Actions.');
                    }
                    $receipt=$this->receipts->reconcile($actor,$runId,$name);
                    if (($receipt['status']??null)!=='completed'
                        || !is_string($receipt['action_id']??null)) {
                        throw new DomainException('Finalized Candidate Run lost its independently attested Action receipt.');
                    }
                    $actions[$name]=true;
                    $ids[$name]=$receipt['action_id'];
                }
            }
            $handoff=false;
            $proposal=false;
            $leadId=null;
            $documentId=null;
            if (count($ids)===4) {
                // Domain-owned read APIs are the authority on native business
                // artifacts, NOT the Federation Action's completion flag.
                $brief=$this->growth->handoffBrief($org,$candidate);
                $candidateRow=$brief['candidate']??null;
                $attempt=$brief['latest_attempt']??null;
                if (is_array($candidateRow) && is_array($attempt)
                    && ($candidateRow['candidate_id']??null)===$candidate
                    && ($candidateRow['organization_id']??null)===$org
                    && ($candidateRow['subject_type']??null)==='account'
                    && ($candidateRow['subject_id']??null)===$lineage['account_id']
                    && ($candidateRow['status']??null)==='handed_off'
                    && ($attempt['status']??null)==='accepted'
                    && ($attempt['target_domain']??null)==='sales'
                    && ($attempt['target_reference_type']??null)==='sales_lead'
                    && is_string($attempt['target_reference_id']??null)
                    && ctype_digit($attempt['target_reference_id'])) {
                    $candidateLead=(int)$attempt['target_reference_id'];
                    if ($candidateLead>0) {
                        $lead=$this->sales->lead($org,$candidateLead);
                        if (is_array($lead)
                            && (string)($lead['id']??'')===(string)$candidateLead
                            && ($lead['source']??null)==='growth-handoff') {
                            $handoff=true;
                            $leadId=(string)$candidateLead;
                        }
                    }
                }
                if ($handoff) {
                    $action=$this->db->fetchAssociative(
                        'SELECT type,target_type,target_id,idempotency_key
                         FROM cos_actions WHERE organization_id=:org AND id=:id',
                        ['org'=>$org,'id'=>$ids['proposal']],
                    );
                    if (is_array($action)
                        && $action['type']==='documents.proposal.prepare'
                        && $action['target_type']==='growth_candidate'
                        && $action['target_id']===$candidate
                        && is_string($action['idempotency_key']??null)
                        && str_starts_with($action['idempotency_key'],'fed:')) {
                        $key='fed-proposal-'.substr(hash('sha256',$action['idempotency_key']),0,40);
                        $documentIdExpected='DOC-'.strtoupper(substr(hash('sha256',
                            $org.':generate:'.$key.'-generate'),0,20));
                        $relationId='REL-'.strtoupper(substr(hash('sha256',
                            $org.':attach:'.$key.'-attach'),0,20));
                        $relation=$this->documents->findRelation($org,$relationId);
                        $document=$this->documents->view($org,$documentIdExpected);
                        if (is_array($relation) && is_array($document)
                            && ($relation['organization_id']??null)===$org
                            && ($relation['document_id']??null)===$documentIdExpected
                            && ($relation['related_type']??null)==='sales.lead'
                            && (string)($relation['related_id']??'')===$leadId
                            && ($document['organization_id']??null)===$org
                            && ($document['document_id']??null)===$documentIdExpected
                            && ($document['current_version_source']??null)==='template'
                            && ($document['status']??null)==='active') {
                            $proposal=true;
                            $documentId=$documentIdExpected;
                        }
                    }
                }
            }
            $subjects[]=[
                'plan_id'=>(string)$row['plan_id'],
                'candidate_id'=>$candidate,
                'account_id'=>$lineage['account_id'],
                'plan_state'=>(string)$row['plan_state'],
                'run_id'=>$runId,
                'run_state'=>$runState,
                'verified_actions'=>$actions,
                'native_handoff_verified'=>$handoff,
                'native_proposal_verified'=>$proposal,
                'sales_lead_id'=>$leadId,
                'document_id'=>$documentId,
            ];
        }
        if (count($subjects)>FederationGoalAggregateProjector::LIMIT) {
            throw new DomainException('Goal contains more than fifty Candidate Plan lineages.');
        }
        $aggregate=$this->projector->project($goal,$subjects);
        $aggregate['evidence_policy']='live_native_read_models_and_completed_action_receipts_v1';
        $aggregate['read_only']=true;
        return $aggregate;
    }
    /**
     * Independently requested, evidence-only business outcome evaluation.
     * Never authorizes or replays an Action. No user-supplied metric or
     * evidence is accepted; only the single exact native-proposal Goal metric
     * is supported. The transaction locks the Goal against concurrent fan-out.
     *
     * @return array<string,mixed>
     */
    public function recordVerifiedProposalOutcome(TenantContext $actor,string $goalId): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$goalId)) {
            throw new DomainException('Unauthorized aggregate Goal evaluation.');
        }
        $org=$actor->organizationId()->value();
        return $this->db->transactional(function()use($actor,$goalId,$org):array {
            $row=$this->db->fetchAssociative(
                'SELECT goal_id,current_spec_version FROM cos_federation_goals
                 WHERE organization_id=:org AND goal_id=:goal FOR UPDATE',
                ['org'=>$org,'goal'=>$goalId],
            );
            if (!$row) {
                throw new DomainException('Tenant Goal is unavailable for independent evaluation.');
            }
            $goal=$this->goals->specification($actor,$goalId);
            if ($goal===null || $goal->version!==(int)$row['current_spec_version']
                || count($goal->criteria)!==1
                || $goal->criteria[0]['id']!=='federation.verified_proposals'
                || $goal->criteria[0]['operator']!=='at_least'
                || !is_int($goal->criteria[0]['expected'])
                || $goal->criteria[0]['expected']<1
                || $goal->criteria[0]['expected']>FederationGoalAggregateProjector::LIMIT) {
                throw new DomainException('Goal has no approved native proposal metric; Sales revenue cannot be inferred.');
            }
            // The read is repeated within the Goal transaction. Every
            // completed child Action and native Growth/Sales/Document fact is
            // freshly re-attested before persisted Goal credit is permitted.
            $aggregate=$this->inspect($actor,$goalId);
            $count=$aggregate['candidate_plans'];
            $proof=$aggregate['native_proposals_prepared'];
            if ($count<$goal->criteria[0]['expected']
                || $count!==$proof
                || $aggregate['plan_states']['completed']!==$count
                || $aggregate['native_sales_handoffs']!==$count
                || $aggregate['aggregate_state']!=='ready_for_independent_goal_evaluation') {
                throw new DomainException('Goal contains pending, unapproved or unverifiable native candidate outcomes.');
            }
            $references=[];
            foreach ($aggregate['items'] as $item) {
                if (($item['native_proposal_verified']??null)!==true
                    || !is_string($item['run_id']??null)
                    || !is_string($item['sales_lead_id']??null)
                    || !is_string($item['document_id']??null)) {
                    throw new DomainException('Invalid native outcome entry in Goal aggregate.');
                }
                $references[]='federation:native_proposal:v1:'.hash('sha256',
                    implode("\0",[$org,$goalId,$item['plan_id'],$item['run_id'],
                        $item['candidate_id'],$item['sales_lead_id'],$item['document_id']])
                );
            }
            sort($references,SORT_STRING);
            if (count(array_unique($references))!==$count) {
                throw new DomainException('Duplicate native proposal outcome fingerprint.');
            }
            $evaluation=$this->evaluator->evaluate($goal,[
                'federation.verified_proposals'=>[
                    'value'=>$proof,'evidence'=>$references,
                    'source'=>'federation.native_growth_sales_documents.v1',
                    'attribution'=>'native_multi_domain_completed_actions',
                    'verified_actions'=>$count*4,
                ],
            ]);
            if ($evaluation['result']!=='satisfied') {
                throw new DomainException('Canonical deterministic Goal criterion was not satisfied.');
            }
            $evaluationId='eval-'.bin2hex(random_bytes(12));
            $evaluation['evaluation_id']=$evaluationId;
            $evaluation['evidence_policy']='native_goal_aggregate_v1';
            $evaluation['verified_candidate_count']=$count;
            $evaluation['business_outcome_verified']=true;
            $this->db->insert('cos_federation_evaluations',[
                'organization_id'=>$org,'evaluation_id'=>$evaluationId,
                'goal_id'=>$goalId,'spec_version'=>$goal->version,
                'result'=>'satisfied',
                'evaluation_json'=>json_encode($evaluation,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                'evaluated_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))
                    ->format('Y-m-d H:i:s.u'),
            ]);
            return $evaluation;
        });
    }

}
