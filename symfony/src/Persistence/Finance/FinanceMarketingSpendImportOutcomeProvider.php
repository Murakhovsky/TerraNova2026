<?php
declare(strict_types=1);
namespace App\Persistence\Finance;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Finance\Automation\Action\FinanceMarketingSpendImportActionHandler;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Source-only immutable Finance intent plus exact revision event, never settlement. */
final readonly class FinanceMarketingSpendImportOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION='finance.marketing.source_observation_imported';
    public function __construct(private Connection $db,private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'finance';}
    public function supports(string $criterionId):bool{return $criterionId===self::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {throw new FederationOutcomeSourceNotReady('Finance source import outcome requires canonical Run.');}

    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid Finance source outcome scope.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.input_digest,w.state,s.step_id,s.state AS step_state,s.result_reference,s.capability_id,s.idempotency_key,p.plan_json
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
            WHERE w.organization_id=:org AND BINARY w.organization_id=BINARY :exact_org AND w.run_id=:run AND w.definition_id=:definition',
            ['org'=>$org,'exact_org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan']);
        if(count($rows)!==1||$rows[0]['state']!=='completed'||$rows[0]['step_state']!=='completed')
            throw new FederationOutcomeSourceNotReady('Completed source import Action required.');
        $row=$rows[0];
        if($row['capability_id']!==FinanceMarketingSpendImportActionHandler::IMPORT
            ||!preg_match('/^action:[a-f0-9]{32}$/D',(string)$row['result_reference'])
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)$row['idempotency_key']))
            throw new DomainException('Unrelated Action cannot satisfy native Finance source import.');
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$row['step_id'],$row['result_reference'],$row['input_digest']);
        $steps=json_decode($row['plan_json'],true,32,JSON_THROW_ON_ERROR)['steps']??[];
        $input=$steps[0]['input']??[];$p=$input['parameters']??[];
        if(!$proof->verified||count($steps)!==1||($input['target_type']??null)!=='marketing_spend_snapshot'
            ||($input['target_id']??null)!==($p['source_snapshot_ref']??null)||!is_string($p['ad_account_ref']??null)
            ||!is_string($p['source_snapshot_ref']??null)||!is_int($p['expected_source_revision']??null)||!is_string($p['expected_source_hash']??null))
            throw new DomainException('Invalid approved Finance source evidence reference.');
        $r=$this->db->fetchAllAssociative("SELECT i.finance_observation_id,i.expected_revision,i.expected_source_hash,e.id AS event_id
            FROM cos_finance_spend_import_action_intents i
            JOIN cos_finance_spend_observations o ON o.organization_id=i.organization_id AND o.id=i.finance_observation_id
            JOIN cos_finance_spend_evidence_events e ON e.organization_id=i.organization_id AND e.observation_id=o.id
                AND e.source_revision=i.expected_revision AND e.source_hash=i.expected_source_hash
            WHERE i.organization_id=:org AND BINARY i.organization_id=BINARY :exact_org AND i.idempotency_key=:key
                AND i.state='SUCCEEDED' AND i.ad_account_ref=:account AND i.source_snapshot_ref=:snapshot
                AND o.ad_account_ref=i.ad_account_ref AND o.source_snapshot_ref=i.source_snapshot_ref
                AND o.evidence_verification='UNVERIFIED_SOURCE' AND o.provider='meta' AND o.source_domain='marketing'
                AND i.expected_revision=:revision AND i.expected_source_hash=:hash",
            ['org'=>$org,'exact_org'=>$org,'key'=>'wf:'.$row['idempotency_key'],'account'=>$p['ad_account_ref'],
             'snapshot'=>$p['source_snapshot_ref'],'revision'=>$p['expected_source_revision'],'hash'=>$p['expected_source_hash']]);
        if(count($r)!==1)throw new DomainException('Native Finance source import does not match the approved fingerprint and event.');
        return ['value'=>1,'evidence'=>['finance:source_observation:'.$r[0]['finance_observation_id'].':'.$r[0]['event_id']],
            'source'=>'finance.native_marketing_source_import.v1','window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
