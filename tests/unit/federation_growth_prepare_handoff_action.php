<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Domains\Growth\Automation\Action\FederatedPrepareHandoffHandler;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;

$boundary = new class implements GrowthApplicationBoundary {
    public array $calls = [];
    public string $status = 'ready_for_handoff';
    public function detectSignal(string $org,int $actor,string $corr,string $key,array $input):array{return [];}
    public function ingestExternalSignal(string $org,int $actor,string $corr,string $source,string $key,array $input):array{return [];}
    public function detectCandidate(string $org,int $actor,string $corr,string $key,array $input):array{return [];}
    public function researchCandidate(string $org,int $actor,string $corr,string $id,string $key,array $input):array{return [];}
    public function scoreCandidate(string $org,int $actor,string $corr,string $id,string $key,array $input):array{return [];}
    public function qualifyCandidate(string $org,int $actor,string $corr,string $id,string $reason,string $key):array{return [];}
    public function monitorCandidate(string $org,int $actor,string $corr,string $id,string $reason,string $key):array{return [];}
    public function disqualifyCandidate(string $org,int $actor,string $corr,string $id,string $reason,string $key):array{return [];}
    public function prepareHandoff(string $org,int $actor,string $corr,string $id,string $key,array $input):array {
        $this->calls[]=[$org,$actor,$corr,$id,$key,$input];
        return ['candidate'=>['candidate_id'=>$id,'status'=>$this->status],
            'handoff'=>['target_domain'=>'sales']];
    }
    public function viewSignal(string $org,string $id):?array{return null;}
    public function viewCandidate(string $org,string $id):?array{return null;}
};
$handler = new FederatedPrepareHandoffHandler($boundary);
$parameters=['expected_value'=>'value','recommended_play'=>'consultation','recommended_action'=>'contact decision maker'];
$make=static fn(array $input, ActionStatus $status=ActionStatus::Running):Action=>
    new Action('a-1','org-one',FederatedPrepareHandoffHandler::TYPE,'growth_candidate','GC-1',
        $input,'USER','44','APPROVAL_REQUIRED','HIGH','fed:prep-1',
        new DateTimeImmutable('2026-10-09T14:00:00Z'),$status,'correl');
if($handler->execute($make([]))->successful || $boundary->calls!==[]) {
    throw new RuntimeException('Growth handoff preparation accepted missing approved fields.');
}
if($handler->execute($make($parameters,ActionStatus::Proposed))->successful || $boundary->calls!==[]) {
    throw new RuntimeException('Unapproved Growth handoff preparation executed.');
}
$boundary->status='qualified';
if($handler->execute($make($parameters))->successful) {
    throw new RuntimeException('Unpersisted ready-for-handoff status accepted.');
}
$boundary->status='ready_for_handoff';
$result=$handler->execute($make($parameters));
if(!$result->successful || $result->data['goal_outcome_verified']!==false
    || $boundary->calls[1]!==['org-one',44,'correl','GC-1','fed:prep-1',$parameters]) {
    throw new RuntimeException('Domain-owned Growth handoff preparation binding failed.');
}
echo "Federation Growth handoff preparation: validated Action status, immutable params, persisted stage PASS.\n";
