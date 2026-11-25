<?php
declare(strict_types=1);
namespace App\Persistence\Sales;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Domains\Sales\Application\Contract\SalesOrderRepositoryInterface;
use Domains\Sales\Application\Contract\SalesCommerceOrderEvidencePort;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Automation\Action\SalesGovernedOrderHandler;
use Domains\Sales\Domain\Policy\SalesOrderTerms;

/** Observes persisted Sales business evidence, never an Action status alone. */
final readonly class SalesOrderRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION='sales.order.native_operation_verified';
    public function __construct(private Connection $db,private SalesOrderRepositoryInterface $orders,
        private SalesCommerceOrderEvidencePort $placements,private SalesMutationReceiptRepositoryInterface $nativeReceipts,
        private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'sales';}
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
            JOIN cos_actions a ON a.organization_id=s.organization_id AND s.result_reference=CONCAT("action:",a.id)
            WHERE w.organization_id=? AND BINARY w.organization_id=BINARY ? AND w.run_id=? AND w.definition_id="federation.approved-plan"',
            [$org,$org,$runId]);
        if(count($rows)!==1||$rows[0]['state']!=='completed'||$rows[0]['step_state']!=='completed')
            throw new FederationOutcomeSourceNotReady('Completed single-step order Run required.');
        $r=$rows[0];
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$r['step_id'],$r['result_reference'],$r['input_digest']);
        if(!$proof->verified)throw new DomainException('Untrusted order Action receipt.');
        $p=json_decode($r['parameters'],true,32,JSON_THROW_ON_ERROR);
        $type=$r['capability_id'];
        if(!in_array($type,SalesGovernedOrderHandler::types(),true))throw new DomainException('Unrelated Sales Action.');
        if($type===SalesGovernedOrderHandler::PREFIX.'create'){
            $terms=SalesOrderTerms::normalize($p);$owner=$p['owner_id']??(int)$r['source_id'];$deal=$p['deal_id']??null;
            $native=$this->nativeReceipts->find($org,'order.native.create',$r['idempotency_key']);
            $hash=hash('sha256',json_encode([(int)$r['source_id'],$owner,$deal,$terms],JSON_THROW_ON_ERROR));
            if(!is_string($native)||!preg_match('/^([a-f0-9]{64}):([a-f0-9]{32})$/D',$native,$m)||!hash_equals($hash,$m[1]))
                throw new DomainException('Native Order creation receipt differs from approved terms.');
            $order=$this->orders->get($org,$m[2]);$fact=$this->placements->placement($org,$m[2]);
            if($order===null||$fact===null||$fact['organization_id']!==$org||$fact['order_id']!==$m[2]||
                $fact['correlation_id']!==$r['correlation_id']||$fact['deal_id']!==$deal||$order['owner_id']!==$owner||
                $fact['order_total']!==$terms['total']||$fact['currency']!==$terms['currency']||
                (array)$fact['attribution_observations']!==$terms['attribution']||$order['lines']!=$terms['lines'])
                throw new DomainException('Native Order placement differs from approved terms.');
            $evidence='sales:order:'.$m[2].':'.$fact['event_id'];
        }else{
            $op=substr($type,strlen(SalesGovernedOrderHandler::PREFIX.'lifecycle.'));
            $native=$this->nativeReceipts->find($org,'order.lifecycle.'.$op,$r['idempotency_key']);
            $factRow=$this->db->fetchAssociative('SELECT event_id,payload FROM sales_order_evidence WHERE organization_id=? AND BINARY organization_id=BINARY ? AND order_id=? AND revision=?',
                [$org,$org,$p['order_id'],$p['expected_revision']+1]);
            if(!is_string($native)||!preg_match('/^[a-f0-9]{64}$/D',$native)||!$factRow)
                throw new DomainException('Native lifecycle receipt missing.');
            $fact=json_decode($factRow['payload'],true,32,JSON_THROW_ON_ERROR);
            $order=$this->orders->get($org,$p['order_id']);
            if($order===null||$order['revision']<$p['expected_revision']+1||$fact['organization_id']!==$org||
                $fact['order_id']!==$p['order_id']||$fact['event_id']!==$factRow['event_id']||$fact['event_type']!=='sales.commerce.order.'.$op||
                $fact['revision']!==$p['expected_revision']+1||$fact['reference']!==$p['reference']||
                $fact['actor_id']!==(int)$r['source_id']||$fact['correlation_id']!==$r['correlation_id']||
                (isset($p['items'])&&array_sum(array_column($p['items'],'quantity'))!==array_sum(array_column($fact['effects'],'quantity'))))
                throw new DomainException('Native lifecycle event differs from approved operation.');
            $evidence='sales:order:'.$p['order_id'].':'.$fact['event_id'];
        }
        return ['value'=>1,'evidence'=>[$evidence],'source'=>'sales.native_order_operation.v1',
            'window_start'=>$from->format('Y-m-d\TH:i:s.uP'),'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),
            'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
