<?php
declare(strict_types=1);

namespace App\Persistence\Sales;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Contract\SalesActivityEvidenceReadPort;
use Domains\Sales\Application\Service\SalesActivityOutcomeEvidence;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Domains\Sales\Application\Support\DealStateSnapshot;
use Domains\Sales\Automation\Action\SalesGovernedBusinessOperations;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Independently attest receipt-bound native state, not a successful Action flag.
 *  Current-state proof is intentionally conservative when subsequent steps change that state.
 */
final readonly class SalesBusinessRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION = 'sales.business.native_operation_verified';
    private const DEAL_OPERATIONS = [
        'sales.governed.deal.owner.assign'=>'owner.assign',
        'sales.governed.deal.commercial.set'=>'commercial.set',
        'sales.governed.pipeline.stage.manage'=>'stage.manage',
        'sales.governed.deal.outcome.record'=>'outcome.record',
    ];

    public function __construct(
        private Connection $db,
        private SalesWorkspaceReadModelInterface $sales,
        private SalesMutationReceiptRepositoryInterface $nativeReceipts,
        private DoctrineWorkflowActionReceiptVerifier $receipts,
        private SalesActivityEvidenceReadPort $activityEvidence,
    ) {}

    public function domain(): string { return 'sales'; }
    public function supports(string $criterionId): bool { return $criterionId===self::CRITERION; }
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to): array
    { throw new FederationOutcomeSourceNotReady('Native Sales business outcome requires canonical Run.'); }

    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || !$this->supports($criterionId) || $to<=$from) throw new DomainException('Invalid Sales business outcome scope.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.state,w.input_digest,s.step_id,s.state AS step_state,s.result_reference,s.capability_id,
            a.id AS action_id,a.type AS action_type,a.parameters,a.target_id,a.source_id,a.idempotency_key
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            LEFT JOIN cos_actions a ON a.organization_id=s.organization_id AND s.result_reference=CONCAT("action:",a.id)
            WHERE w.organization_id=? AND BINARY w.organization_id=BINARY ? AND w.run_id=? AND w.definition_id="federation.approved-plan"',
            [$org,$org,$runId]);
        $evidence=[];
        foreach ($rows as $r) {
            if (!in_array($r['capability_id'],SalesGovernedBusinessOperations::types(),true)) continue;
            if (!isset(self::DEAL_OPERATIONS[$r['capability_id']]) && $r['capability_id']!=='sales.governed.lead.state.manage'
                && !SalesActivityOutcomeEvidence::supports($r['capability_id']))
                throw new FederationOutcomeSourceNotReady('Independent native proof not yet available for every Sales operation in this Run.');
            if ($r['state']!=='completed'||$r['step_state']!=='completed'||!is_string($r['action_id']??null))
                throw new FederationOutcomeSourceNotReady('Completed Sales business Run required.');
            $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$r['step_id'],$r['result_reference'],$r['input_digest']);
            if (!$proof->verified || $r['action_type']!==$r['capability_id']) throw new DomainException('Untrusted Sales Action receipt.');
            $reference=$this->verify($org,$r);
            if (in_array($reference,$evidence,true)) throw new DomainException('Duplicate Sales business proof.');
            $evidence[]=$reference;
        }
        if ($evidence===[]) throw new FederationOutcomeSourceNotReady('No supported Sales business operations in this Run.');
        return ['value'=>count($evidence),'evidence'=>$evidence,'source'=>'sales.native_business_operation.v1',
            'window_start'=>$from->format('Y-m-d\TH:i:s.uP'),'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),
            'attribution'=>'run_linked_action','run_id'=>$runId];
    }

    private function verify(string $org,array $r): string
    {
        $p=json_decode($r['parameters'],true,32,JSON_THROW_ON_ERROR);
        $type=$r['capability_id'];
        $user=(int)$r['source_id'];
        $key=$r['idempotency_key'];
        if (!is_array($p)||$user<1||!is_string($key)||!preg_match('/^wf:[a-f0-9]{64}$/D',$key))
            throw new DomainException('Invalid Sales receipt identity.');
        if ($type==='sales.governed.lead.state.manage') {
            $id=$p['lead_id'];
            $hash=hash('sha256',json_encode([$user,$id,$p['expected_status'],$p['target_status']],JSON_THROW_ON_ERROR));
            $receipt=$this->nativeReceipts->find($org,'lead.state.manage',$key);
            if (!is_string($receipt)||!hash_equals('done:'.$hash,$receipt)||(string)$id!==$r['target_id'])
                throw new DomainException('Native lead receipt differs from approved operation.');
            $row=$this->sales->lead($org,$id);
            if ($row===null||($row['organization_id']??null)!==$org||!in_array($row['id']??null,[$id,(string)$id],true)
                ||($row['status']??null)!==$p['target_status'])
                throw new FederationOutcomeSourceNotReady('Approved lead state is no longer observable.');
            return 'sales:lead:'.$id.':'.$key;
        }
        if (SalesActivityOutcomeEvidence::supports($type)) {
            return (new SalesActivityOutcomeEvidence($this->activityEvidence,$this->nativeReceipts))
                ->verify($org,$type,$user,(string)$r['target_id'],$key,$p);
        }
        $op=self::DEAL_OPERATIONS[$type];
        $id=$p['deal_id'];
        ksort($p);
        $hash=hash('sha256',json_encode([$user,$op,$p],JSON_THROW_ON_ERROR));
        $receipt=$this->nativeReceipts->find($org,'deal.'.$op,$key);
        if (!is_string($receipt)||preg_match('/^done:([a-f0-9]{64}):[01]$/D',$receipt,$m)!==1
            ||!hash_equals($hash,$m[1])||(string)$id!==$r['target_id'])
            throw new DomainException('Native deal receipt differs from approved operation.');
        $row=$this->sales->deal($org,$id);
        if ($row===null) throw new FederationOutcomeSourceNotReady('Native deal is no longer observable.');
        $state=DealStateSnapshot::fromNative($row,$org,$id);
        $matches=match($op) {
            'owner.assign'=>$state['owner_id']===$p['owner_id'],
            'commercial.set'=>$state['deal_value']===$p['deal_value']&&$state['currency']===$p['currency'],
            'stage.manage'=>$state['stage_id']===$p['target_stage_id']&&!in_array($state['status'],['closed','lost'],true),
            'outcome.record'=>$state['stage_id']===$p['target_stage_id']
                &&$state['status']===($p['outcome']==='won'?'closed':'lost')
                &&($p['outcome']==='won'||$state['lost_reason_id']===$p['lost_reason_id']),
            default=>false,
        };
        if (!$matches) throw new FederationOutcomeSourceNotReady('Approved deal state is no longer observable.');
        return 'sales:deal:'.$id.':'.$key;
    }
}
