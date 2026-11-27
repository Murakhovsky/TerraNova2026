<?php
declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\EntityLinkProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\SearchProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\EntityLink;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Search\SearchResultMatcher;
use App\Web\Experience\Shell\ShellCommandItem;
use App\Web\Experience\Unified\Business\BusinessRecordProvider;
use App\Web\Experience\Unified\Business\WorkspaceCapabilityRead;
use DomainException;

/** Finance web surface is a read projection. Monetary writes remain approved Actions. */
final readonly class FinanceWebProvider implements NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface, BusinessRecordProvider, EntityLinkProviderInterface
{
    public function __construct(private SearchResultMatcher $matcher,private WorkspaceCapabilityRead $read) {}

    public function serviceId():string { return 'financeNavigationContributor'; }

    public function navigation(WebExtensionContext $context):array
    {
        return [
            new NavigationContribution('finance','Finance',WorkspaceCapabilityRead::workspacePath('finance.budget'),'FN',45),
            new NavigationContribution('finance-budgets','Budgets',WorkspaceCapabilityRead::workspacePath('finance.budget'),priority:10,parentKey:'finance'),
            new NavigationContribution('finance-payments','Order payments',WorkspaceCapabilityRead::workspacePath('finance.payments'),priority:20,parentKey:'finance'),
        ];
    }

    public function search(WebExtensionContext $context,string $query,int $limit=10):array
    {
        $limit=max(1,min(50,$limit));
        $navigation=$this->matcher->match([
            new SearchResult('finance.budgets','Budgets',WorkspaceCapabilityRead::workspacePath('finance.budget'),'workspace','Budget envelopes and reservations'),
            new SearchResult('finance.payments','Order payments and reconciliation',WorkspaceCapabilityRead::workspacePath('finance.payments'),'workspace','Documentary evidence; no payment provider writes'),
        ], $query, $limit);
        $query=trim($query);
        $workspace=preg_match('/^finb-[a-f0-9]{24}$/D',$query)?'finance.budget':
            (preg_match('/^[a-f0-9]{32}$/D',$query)?'finance.payments':null);
        if ($workspace===null) return $navigation;
        try {
            return array_slice([...$this->records($context,$workspace,$query,1)['items'],...$navigation],0,$limit);
        } catch (\Throwable) {
            return $navigation;
        }
    }

    public function commands(WebExtensionContext $context):array
    {
        return [
            new ShellCommandItem('finance.budgets.open','Open budgets',WorkspaceCapabilityRead::workspacePath('finance.budget'),'navigation','Finance'),
            new ShellCommandItem('finance.payments.open','Open order payments',WorkspaceCapabilityRead::workspacePath('finance.payments'),'navigation','Finance'),
        ];
    }

    public function workspaces(WebExtensionContext $context):array
    {
        return [
            new WorkspaceDefinition('finance.budget','Budget envelopes',WorkspaceCapabilityRead::workspacePath('finance.budget'),'finance.budget',10),
            new WorkspaceDefinition('finance.payments','Order payment reconciliation',WorkspaceCapabilityRead::workspacePath('finance.payments'),'finance.order_payment',20),
        ];
    }

    public function supportsRecords(string $workspaceId):bool
    {
        return in_array($workspaceId,['finance.budget','finance.payments'],true);
    }

    public function records(WebExtensionContext $context,string $workspaceId,string $query,int $page):array
    {
        if(!$this->supportsRecords($workspaceId))throw new \InvalidArgumentException('Unsupported workspace.');
        $budget=$workspaceId==='finance.budget';
        $data=$budget?$this->read->query($context,'finance.budget.envelope.list',['q'=>$query,'offset'=>($page-1)*20,'limit'=>20])
            :$this->read->query($context,'sales.order.list',['q'=>$query,'scope'=>'auto','offset'=>($page-1)*20,'limit'=>20]);
        $hits=[];
        foreach($data['items']??[] as $row){
            $id=(string)($budget?($row['id']??''):($row['order_id']??''));
            if(!preg_match($budget?'/^finb-[a-f0-9]{24}$/D':'/^[a-f0-9]{32}$/D',$id))continue;
            $ref=new EntityRef($budget?'finance.budget':'finance.order_payment',$id);
            $hits[]=WorkspaceCapabilityRead::hit($ref->type,$id,
                $budget?(string)$row['name']:'Order '.$id,WorkspaceCapabilityRead::workspacePath($workspaceId,$ref),
                $budget?$row['state'].' · '.$row['currency']:$row['status'].' · '.$row['channel']);
        }
        return ['items'=>$hits,'has_more'=>(bool)($data['has_more']??false),
            'notice'=>$budget?null:'This is an authorized Sales order list; payment evidence is read separately.'];
    }

    public function record(WebExtensionContext $context,string $workspaceId,EntityRef $entity):?array
    {
        if ($workspaceId==='finance.budget'&&$entity->type==='finance.budget'&&preg_match('/^finb-[a-f0-9]{24}$/D',$entity->id)) {
            $data=$this->read->query($context,'finance.budget.envelope.get',['id'=>$entity->id]);
            $row=$data['envelope']??null;
            if (!is_array($row)||($row['id']??null)!==$entity->id) return null;
            $reservations=$this->read->query($context,'finance.budget.reservation.list',['envelope_id'=>$entity->id]);
            return [
                'hit'=>WorkspaceCapabilityRead::hit($entity->type,$entity->id,(string)$row['name'],WorkspaceCapabilityRead::workspacePath($workspaceId,$entity),(string)$row['state']),
                'facts'=>WorkspaceCapabilityRead::facts($row,['state'=>'State','currency'=>'Currency','period_start'=>'Period start','period_end'=>'Period end',
                    'limit_minor'=>'Limit (minor units)','reserved_minor'=>'Reserved (minor units)','committed_minor'=>'Committed (minor units)','available_minor'=>'Available (minor units)','revision'=>'Revision']),
                'tables'=>[WorkspaceCapabilityRead::table('Budget reservations',['id'=>'Reference','state'=>'State','amount_minor'=>'Amount (minor units)','created_at'=>'Created'],$reservations['items']??[],'First 50 native reservations. Changes require approved Finance Actions.')],
            ];
        }
        if ($workspaceId!=='finance.payments'||$entity->type!=='finance.order_payment'||!preg_match('/^[a-f0-9]{32}$/D',$entity->id)) return null;
        // An order must be accessible under Sales ownership too; do not reveal
        // Finance evidence for a same-tenant order outside the actor's Sales scope.
        $order=$this->read->query($context,'sales.order.read',['order_id'=>$entity->id]);
        if (($order['order']['order_id']??null)!==$entity->id) return null;
        $data=$this->read->query($context,'finance.order.financial_evidence.read',['order_id'=>$entity->id]);
        $operations=$this->read->query($context,'finance.order.payment.operations.read',['order_id'=>$entity->id,'offset'=>0,'limit'=>50]);
        if (($operations['order_id']??null)!==$entity->id) throw new DomainException('Payment readback unavailable.');
        $evidence=$data['evidence']??null;
        if ($evidence!==null && (!is_array($evidence)||($evidence['order_id']??null)!==$entity->id||($evidence['organization_id']??null)!==$context->organizationId)) {
            throw new DomainException('Payment evidence unavailable.');
        }
        $facts=is_array($evidence)?WorkspaceCapabilityRead::facts($evidence,[
            'currency'=>'Currency','captured_amount'=>'Reconciled capture','refunded_amount'=>'Reconciled refund','chargeback_amount'=>'Reconciled chargeback',
            'net_collected_amount'=>'Net collected','outstanding_amount'=>'Outstanding','evidence_reference'=>'Evidence reference','revision'=>'Evidence revision',
        ]):[['label'=>'Financial evidence','value'=>'Not available; no confirmed collection amount is inferred.']];
        return [
            'hit'=>WorkspaceCapabilityRead::hit($entity->type,$entity->id,'Order '.$entity->id,WorkspaceCapabilityRead::workspacePath($workspaceId,$entity),'Payment evidence'),
            'facts'=>$facts,
            'tables'=>[WorkspaceCapabilityRead::table('Payment operations',['operation_id'=>'Reference','kind'=>'Kind','amount'=>'Amount','state'=>'State','reconciliation_id'=>'Reconciliation','created_at'=>'Created'],$operations['operations']??[],(($operations['has_more']??false)?'First 50 operations. ':'').'Pending registration is not reconciled money. No provider payment is executed here.')],
        ];
    }

    public function link(WebExtensionContext $context,EntityRef $entity):?EntityLink
    {
        if ($entity->type==='finance.budget'&&preg_match('/^finb-[a-f0-9]{24}$/D',$entity->id)) return new EntityLink('Budget',WorkspaceCapabilityRead::workspacePath('finance.budget',$entity));
        if ($entity->type==='finance.order_payment'&&preg_match('/^[a-f0-9]{32}$/D',$entity->id)) return new EntityLink('Payments',WorkspaceCapabilityRead::workspacePath('finance.payments',$entity));
        return null;
    }
}
