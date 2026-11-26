<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingAttributionReceiptReadPort;
use Domains\Marketing\Application\Service\MarketingAttributionSweepService;
use Domains\Marketing\Automation\Action\MarketingAttributionSweepHandler;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

final readonly class MarketingAttributionSweepOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION='marketing.attribution.sweep_recorded';
    public function __construct(private Connection $db,private MarketingAttributionReceiptReadPort $native,
        private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'marketing';}
    public function supports(string $criterionId):bool{return $criterionId===self::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {throw new FederationOutcomeSourceNotReady('Sweep requires linked canonical Workflow Run.');}
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid sweep outcome request.');
        $verified=$this->verifiedReceipt($actor,$runId);
        return ['value'=>1,'evidence'=>['marketing:attribution_sweep:'.$verified['key']],
            'source'=>'marketing.native_attribution.sweep_recorded.v1','window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
    /** Exact independently attested receipt for a completed canonical run. */
    public function verifiedReceipt(TenantContext $actor,string $runId):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))
            throw new DomainException('Sweep receipt access denied.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.input_digest,w.state,w.actor_id,s.step_id,s.state AS step_state,
            s.idempotency_key,s.result_reference,p.plan_json
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
            WHERE w.organization_id=:org AND BINARY w.organization_id=BINARY :exact_org AND w.run_id=:run AND w.definition_id=:definition AND s.capability_id=:cap',
            ['org'=>$org,'exact_org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan','cap'=>MarketingAttributionSweepHandler::TYPE]);
        if(count($rows)!==1||$rows[0]['state']!=='completed'||$rows[0]['step_state']!=='completed'||!is_string($rows[0]['result_reference']??null))
            throw new FederationOutcomeSourceNotReady('Completed sweep action unavailable.');
        $row=$rows[0];
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),(string)$row['step_id'],(string)$row['result_reference'],(string)$row['input_digest']);
        if(!$proof->verified)throw new DomainException('Canonical sweep receipt unverified.');
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        $step=$plan['steps'][0]??null;
        if(count($plan['steps']??[])!==1||!is_array($step)||($step['capability_id']??null)!==MarketingAttributionSweepHandler::TYPE
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)$row['idempotency_key']))
            throw new DomainException('Sweep outcome requires exactly one pinned action.');
        $input=$step['input'];$p=$input['parameters'];$campaign=$input['target_id'];$max=$p['max_pages']??1;
        $key='wf:'.$row['idempotency_key'];
        $stored=$this->native->receipt($org,$key);
        if($stored===null)throw new FederationOutcomeSourceNotReady('Native sweep receipt missing.');
        $h=MarketingAttributionSweepService::requestHash((string)$row['actor_id'],$campaign,$p['plan_id'],$p['source_kind'],$p['window_start'],$p['window_end'],$p['after'],$max);
        $r=$stored['receipt'];
        if(!hash_equals($h,$stored['request_hash'])||($r['model']??null)!=='attribution-sweep-v1'||($r['campaign_id']??null)!==$campaign
            ||($r['max_pages']??null)!==$max||($r['complete_coverage']??null)!==false||($r['financial_mutation']??null)!==false)
            throw new DomainException('Native sweep receipt scope mismatch.');
        foreach(['plan_id','source_kind','window_start','window_end','after'] as $field)
            if(!array_key_exists($field,$r)||$r[$field]!==$p[$field])throw new DomainException('Native sweep receipt input mismatch.');
        foreach(['scanned','recorded_or_already_present','unresolved','pages_processed'] as $field)
            if(!is_int($r[$field]??null)||$r[$field]<0)throw new DomainException('Native sweep receipt count invalid.');
        if($r['pages_processed']<1||$r['pages_processed']>$max||$r['scanned']>50*$r['pages_processed']
            ||$r['recorded_or_already_present']+$r['unresolved']!==$r['scanned']
            ||!array_key_exists('next_after',$r)||($r['page_exhausted']??null)!==($r['next_after']===null))
            throw new DomainException('Native sweep receipt arithmetic invalid.');
        $next=$r['next_after'];
        if($next!==null&&(!is_string($next)||!preg_match($p['source_kind']==='lead'?'/^[1-9][0-9]{0,17}$/D':'/^[a-f0-9]{32}$/D',$next)
            ||$r['pages_processed']!==$max||$r['scanned']!==50*$max
            ||($p['after']!==null&&($p['source_kind']==='lead'?(int)$next<=(int)$p['after']:strcmp($next,$p['after'])<=0))))
            throw new DomainException('Native sweep continuation invalid.');
        return ['key'=>$key,'receipt'=>$r,'requested_by'=>(string)$row['actor_id']];
    }
}
