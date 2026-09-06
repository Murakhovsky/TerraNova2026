<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
use Domains\Sales\Domain\Policy\StageTransitionPolicy;
use Domains\Sales\Model\{DealChangeSet,PipelineDefinition,PipelineStageDefinition,PipelineTransitionDefinition,SalesIntelligenceAssessment};
use Kernel\Agent\{AgentResult};
use Kernel\Agent\Service\StructuredDecisionValidator;
use Domains\Sales\Automation\Agent\SalesIntelligenceAgent;

$new=new PipelineStageDefinition('new','NEW','New',10,false,false,false,5);$won=new PipelineStageDefinition('won','WON','Won',20,true,true,false,100);$lost=new PipelineStageDefinition('lost','LOST','Lost',30,true,false,true,0);$pipeline=new PipelineDefinition('p','org-a','sales','Sales',[$new,$won,$lost]);$transition=new PipelineTransitionDefinition('t','p','new','won',[],true);$policy=new StageTransitionPolicy();
$valid=$policy->evaluate(['pipeline_id'=>'p'],$pipeline,$new,$won,$transition);if(!$valid->allowed||!$valid->requiresApproval)throw new RuntimeException('Valid transition rejected.');
if($policy->evaluate(['pipeline_id'=>'p'],$pipeline,$new,$won,null)->allowed)throw new RuntimeException('Unconfigured transition accepted.');
if(!$policy->evaluate(['pipeline_id'=>'p'],$pipeline,$new,$new,null)->noOp)throw new RuntimeException('Same stage is not idempotent.');
if($policy->evaluate(['pipeline_id'=>'p'],$pipeline,$won,$new,new PipelineTransitionDefinition('back','p','won','new'))->allowed)throw new RuntimeException('Terminal stage was left.');
if($policy->evaluate(['pipeline_id'=>'foreign'],$pipeline,$new,$won,$transition)->allowed)throw new RuntimeException('Cross-pipeline deal accepted.');
foreach([['stage'=>'won'],['stage_id'=>'won'],['pipeline_id'=>'p']] as $bypass){try{DealChangeSet::fromArray($bypass);throw new RuntimeException('Generic DealChangeSet accepted stage mutation.');}catch(InvalidArgumentException){}}
$changes=DealChangeSet::fromArray(['priority'=>'high','probability'=>80]);if($changes->toArray()['probability']!==80.0)throw new RuntimeException('Generic fields failed.');
try{DealChangeSet::fromArray(['probability'=>101]);throw new RuntimeException('Invalid probability accepted.');}catch(InvalidArgumentException){}

$definition=SalesIntelligenceAgent::definition();$output=['decision'=>'FOLLOW_UP','reason'=>'No next contact','confidence'=>.82,'proposed_actions'=>[['type'=>'sales.create_followup','parameters'=>[]]],'evidence'=>['sales_intelligence'=>['deal_health'=>'AT_RISK','risk_level'=>'HIGH','risk_reasons'=>['No next contact'],'opportunity_level'=>'MEDIUM','customer_intent'=>'INTERESTED','objections'=>[],'missing_information'=>[],'next_best_action'=>'sales.create_followup','recommended_timing'=>'today']]];$decision=(new StructuredDecisionValidator())->validate($output,$definition);$assessment=SalesIntelligenceAssessment::fromDecision($decision,$definition->allowedActionTypes);if($assessment->riskLevel!=='HIGH'||$assessment->confidence!==.82)throw new RuntimeException('Sales assessment mapping failed.');
unset($output['evidence']['sales_intelligence']['risk_reasons']);try{(new StructuredDecisionValidator())->validate($output,$definition);throw new RuntimeException('Malformed Sales intelligence accepted.');}catch(InvalidArgumentException){}
echo "Sales V0.3 domain correctness passed.\n";
