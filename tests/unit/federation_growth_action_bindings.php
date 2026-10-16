<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthDecisionBoundary;
use Domains\Growth\Application\Contract\GrowthMarketDiscoveryBoundary;
use Domains\Growth\Automation\Action\FederatedMarketDiscoveryHandler;
use Domains\Growth\Automation\Action\FederatedCandidateQualificationHandler;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;

$called = [];
$market = new class($called) implements GrowthMarketDiscoveryBoundary {
    public array $calls = [];
    public string $status = 'completed';
    public function __construct(array $calls) {}
    public function createUniverse(string $org,int $actor,string $corr,string $key,array $input):array{return [];}
    public function setEnabled(string $org,int $actor,string $corr,string $id,bool $enabled,string $key):array{return [];}
    public function runUniverse(string $org,int $actor,string $corr,string $id,string $key,int $limit=100):array {
        $this->calls[] = [$org,$actor,$corr,$id,$key,$limit];
        return ['status'=>$this->status,'run_id'=>'GMRN-42','collected_count'=>23,'account_count'=>20,'opportunity_count'=>3];
    }
    public function universes(string $org):array{return [];}
    public function universeBrief(string $org,string $id):array{return [];}
    public function considerSignal(string $org,string $account,string $signal,string $corr):void {}
};
$decisions = new class implements GrowthDecisionBoundary {
    public array $calls = [];
    public string $outcome = 'qualified';
    public function createQualificationPolicy(string $org,int $actor,string $corr,string $key,array $input):array{return [];}
    public function reviseQualificationPolicy(string $org,int $actor,string $corr,string $policy,int $base,string $key,array $input):array{return [];}
    public function activateQualificationPolicy(string $org,int $actor,string $corr,string $policy,int $revision,string $key):array{return [];}
    public function evaluateCandidate(string $org,int $actor,string $corr,string $id,string $policy,int $revision,string $key):array {
        $this->calls[] = [$org,$actor,$corr,$id,$policy,$revision,$key];
        return ['evaluation_id'=>'GQEV-7','outcome'=>$this->outcome];
    }
    public function decisionBrief(string $org,string $id):array{return [];}
};
$make = static function(string $type,string $targetType,string $targetId,array $params,
    ActionStatus $status=ActionStatus::Running,string $sourceType='USER'): Action {
    return new Action('act-100','org-test',$type,$targetType,$targetId,$params,$sourceType,'42',
        'APPROVAL_REQUIRED','HIGH','fed:idempotent-42',new DateTimeImmutable('2026-10-09T12:00:00Z'),$status,'corr-101');
};
$discovery = new FederatedMarketDiscoveryHandler($market);
$qualify = new FederatedCandidateQualificationHandler($decisions);
assert($discovery->supports('growth.market.discovery') && !$discovery->supports('growth.send_message'));
assert($qualify->supports('growth.candidate.qualify') && !$qualify->supports('growth.candidate.score'));
$invalid=$make($discovery::TYPE,'growth_candidate','candidate-1',['limit'=>50]);
if ($discovery->execute($invalid)->successful || $market->calls!==[]) throw new RuntimeException('Discovery target bypassed validation.');
$notRunning=$make($discovery::TYPE,'growth_market_universe','u1',['limit'=>50],ActionStatus::Proposed);
if ($discovery->execute($notRunning)->successful || $market->calls!==[]) throw new RuntimeException('Nonrunning Action executed.');
$valid=$make($discovery::TYPE,'growth_market_universe','u1',['limit'=>50]);
$receipt=$discovery->execute($valid);
if (!$receipt->successful || $receipt->data['goal_outcome_verified']!==false || $receipt->data['account_count']!==20
    || $market->calls!==[['org-test',42,'corr-101','u1','fed:idempotent-42',50]]) throw new RuntimeException('Discovery binding failed.');
$market->status='partial';
if ($discovery->execute($valid)->successful) throw new RuntimeException('Partial Growth discovery falsely completed Action.');
$bad=$make($qualify::TYPE,'growth_candidate','candidate-1',['policy_id'=>'p1','policy_revision'=>0]);
if ($qualify->execute($bad)->successful || $decisions->calls!==[]) throw new RuntimeException('Invalid qualification policy accepted.');
$good=$make($qualify::TYPE,'growth_candidate','candidate-1',['policy_id'=>'p1','policy_revision'=>2]);
$qualified=$qualify->execute($good);
if (!$qualified->successful || $qualified->data['outcome']!=='qualified'
    || $decisions->calls!==[['org-test',42,'corr-101','candidate-1','p1',2,'fed:idempotent-42']]) throw new RuntimeException('Qualified policy evaluation binding failed.');
$decisions->outcome='disqualified';
$rejected=$qualify->execute($good);
if (!$rejected->successful || $rejected->data['outcome']!=='disqualified' || $rejected->data['goal_outcome_verified']!==false) throw new RuntimeException('Disqualified result misrepresented.');
echo "Federation Growth real Action bindings: validation, tenant delegation, idempotent parameters and outcome boundaries PASS.\n";
