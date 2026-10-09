<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthHandoffBoundary;
use Domains\Growth\Automation\Action\FederatedSalesHandoffHandler;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;

$port = new class implements GrowthHandoffBoundary {
    public array $calls=[];
    public string $status='ready_for_handoff';
    public function handoffBrief(string $org,string $id):array {
        return ['candidate'=>['candidate_id'=>$id,'target_domain'=>'sales','status'=>$this->status]];
    }
    public function dispatch(string $org,int $actor,string $corr,string $id,string $key):array {
        $this->calls[]=[$org,$actor,$corr,$id,$key];
        return ['attempt'=>['attempt_id'=>'GHAT-1','status'=>'accepted',
            'target_domain'=>'sales','target_reference_type'=>'sales_lead','target_reference_id'=>'42']];
    }
    public function targets():array{return ['sales'];}
};
$handler=new FederatedSalesHandoffHandler($port);
$mk=static fn(string $target='growth_candidate',ActionStatus $status=ActionStatus::Running):Action=>
    new Action('a-1','tenant','growth.handoff.target.sales',$target,'GROW-1',['target_domain'=>'sales'],'USER','7',
        'APPROVAL_REQUIRED','HIGH','fed:h-1',new DateTimeImmutable('2026-10-09T14:00:00Z'),
        $status,'corr');
if ($handler->execute($mk('sales_lead'))->successful || $port->calls!==[]) throw new RuntimeException('Forged handoff target passed validation.');
if ($handler->execute($mk('growth_candidate',ActionStatus::Proposed))->successful || $port->calls!==[]) throw new RuntimeException('Unapproved handoff executed.');
$port->status='qualified';
if ($handler->execute($mk())->successful || $port->calls!==[]) throw new RuntimeException('Unready Candidate executed.');
$port->status='ready_for_handoff';
$receipt=$handler->execute($mk());
if (!$receipt->successful || $receipt->data['sales_lead_id']!=='42'
    || $receipt->data['goal_outcome_verified']!==false
    || $port->calls!==[['tenant',7,'corr','GROW-1','fed:h-1']]) throw new RuntimeException('Native Sales handoff binding failed.');
echo "Federation Growth-to-Sales Action binding and safety checks PASS.\n";
