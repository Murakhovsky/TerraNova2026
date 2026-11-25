<?php
declare(strict_types=1);
namespace App\Persistence\Finance;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Domains\Finance\Application\Contract\CommerceOrderFinancialEvidenceReadPort;
use Domains\Finance\Automation\Action\FinanceOrderPaymentActionHandler as Handler;
use Domains\Finance\Domain\CommerceOrderMoney as Money;

/** Verifies native registration or documentary reconciliation, not movement of bank funds. */
final readonly class FinanceOrderRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION='finance.order.native_operation_verified';
    public function __construct(private Connection $db,private CommerceOrderFinancialEvidenceReadPort $financial,
        private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'finance';}
    public function supports(string $criterionId):bool{return $criterionId===self::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {throw new FederationOutcomeSourceNotReady('Native order outcome requires canonical Run.');}
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(\Kernel\Tenant\Model\TenantPermissions::MANAGE)||!$this->supports($criterionId)||$to<=$from)
            throw new DomainException('Invalid order outcome scope.');
        $rows=$this->db->fetchAllAssociative('SELECT w.state,w.input_digest,s.step_id,s.state AS step_state,s.result_reference,s.capability_id,a.id AS action_id,a.parameters,a.target_id,a.target_type,a.source_id,a.idempotency_key,a.correlation_id
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            LEFT JOIN cos_actions a ON a.organization_id=s.organization_id AND s.result_reference=CONCAT("action:",a.id)
            WHERE w.organization_id=? AND BINARY w.organization_id=BINARY ? AND w.run_id=? AND w.definition_id="federation.approved-plan"',
            [$org,$org,$runId]);
        $rows=array_values(array_filter($rows,static fn(array $r):bool=>in_array($r['capability_id'],[Handler::REGISTER,Handler::RECONCILE],true)));
        if($rows===[])throw new FederationOutcomeSourceNotReady('Completed Finance order Run required.');
        $evidence=[];
        foreach($rows as $r){
            if($r['state']!=='completed'||$r['step_state']!=='completed'||!is_string($r['action_id']??null))
                throw new FederationOutcomeSourceNotReady('Completed native Finance Action required.');
            $reference=$this->verify($org,$runId,$r);
            if(in_array($reference,$evidence,true))throw new DomainException('Duplicate Finance business evidence.');
            $evidence[]=$reference;
        }
        return ['value'=>count($evidence),'evidence'=>$evidence,'source'=>'finance.native_order_documentary_operation.v1',
            'window_start'=>$from->format('Y-m-d\\TH:i:s.uP'),'window_end'=>$to->format('Y-m-d\\TH:i:s.uP'),
            'attribution'=>'run_linked_action','run_id'=>$runId];
    }

    private function verify(string $org,string $runId,array $r):string
    {
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$r['step_id'],$r['result_reference'],$r['input_digest']);
        if(!$proof->verified)throw new DomainException('Untrusted order Action receipt.');
        $p=json_decode($r['parameters'],true,32,JSON_THROW_ON_ERROR);
        $type=$r['capability_id'];$id=$r['target_id'];$n=Money::amount($p['amount']);$key=$r['idempotency_key'];
        if($r['target_type']!=='sales_order'||$p['order_id']!==$id)throw new DomainException('Order payment target differs.');
        if($type===Handler::REGISTER){
            $row=$this->db->fetchAssociative('SELECT * FROM cos_finance_order_operations WHERE organization_id=? AND BINARY organization_id=BINARY ? AND order_id=? AND idempotency_key=?',[$org,$org,$id,$key]);
            $hash=hash('sha256',json_encode([$id,$p['kind'],$n,$p['currency'],$p['source_account'],$p['source_reference'],$p['parent_id']??null,$r['source_id']],JSON_THROW_ON_ERROR));
            if(!$row||(int)$row['amount_minor']!==$n||$row['kind']!==$p['kind']||$row['source_account']!==$p['source_account']||
                $row['source_reference']!==$p['source_reference']||$row['parent_id']!==($p['parent_id']??null))
                throw new DomainException('Native registration differs from approved operation.');
            $evidence='finance:order_registration:'.$row['id'];
        }elseif($type===Handler::RECONCILE){
            $row=$this->db->fetchAssociative('SELECT r.*,o.amount_minor,o.kind,o.source_reference FROM cos_finance_order_reconciliations r JOIN cos_finance_order_operations o ON o.organization_id=r.organization_id AND o.id=r.operation_id AND o.order_id=r.order_id WHERE r.organization_id=? AND BINARY r.organization_id=BINARY ? AND r.order_id=? AND r.idempotency_key=?',[$org,$org,$id,$key]);
            $hash=hash('sha256',json_encode([$id,$p['operation_id'],$n,$p['currency'],$p['source_account'],$p['source_reference'],$p['statement_reference'],$p['kind'],$p['expected_revision'],$r['source_id']],JSON_THROW_ON_ERROR));
            $projection=$this->financial->financialEvidence($org,$id);
            if(!$row||(int)$row['amount_minor']!==$n||$row['kind']!==$p['kind']||$row['source_reference']!==$p['source_reference']||
                $row['operation_id']!==$p['operation_id']||$row['statement_reference']!==$p['statement_reference']||
                (int)$row['revision']!==$p['expected_revision']+1||$projection===null||$projection['organization_id']!==$org||
                $projection['order_id']!==$id||$projection['currency']!==$p['currency']||$projection['revision']<(int)$row['revision']||
                ($projection['revision']===(int)$row['revision']&&$projection['evidence_reference']!==$row['id']))
                throw new DomainException('Native reconciliation or collection readback differs from approved operation.');
            $evidence='finance:order_reconciliation:'.$row['id'];
        }else throw new DomainException('Unrelated Finance Action.');
        if($row['action_id']!==$r['action_id']||(string)$row['actor_id']!==$r['source_id']||
            $row['correlation_id']!==$r['correlation_id']||!hash_equals($row['request_hash'],$hash))
            throw new DomainException('Native Finance receipt provenance differs.');
        return $evidence;
    }
}
