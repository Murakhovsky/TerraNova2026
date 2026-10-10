<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Platform\Orchestration\Goal\FederationGoalAggregateProjector;
use Platform\Orchestration\Goal\GoalSpecification;

$goal=new GoalSpecification(
    'goal-aggregate','tenant-aggregate','71','Fifty verified proposal drafts',
    [['id'=>'federation.verified_proposals','operator'=>'at_least','expected'=>50]],
    ['growth.market.discovery','growth.candidate.qualify','growth.handoff.prepare',
     'growth.handoff.target.sales','documents.proposal.prepare'],
);
$p=new FederationGoalAggregateProjector();
$rows=[];
for($i=1;$i<=50;$i++){
    $candidate='candidate-'.sprintf('%03d',$i);
    $plan='plan-'.substr(hash('sha256',
        $goal->organizationId."\0".$goal->goalId."\0".$candidate),0,24);
    $rows[]=[
        'candidate_id'=>$candidate,'account_id'=>'account-'.sprintf('%03d',$i),
        'plan_id'=>$plan,'plan_state'=>'approved','run_id'=>'run-'.sprintf('%03d',$i),
        'run_state'=>'completed',
        'verified_actions'=>array_fill_keys(FederationGoalAggregateProjector::STAGES,true),
        'native_handoff_verified'=>true,'native_proposal_verified'=>true,
        'sales_lead_id'=>(string)(900+$i),'document_id'=>'DOC-'.sprintf('%03d',$i),
    ];
}
$complete=$p->project($goal,$rows);
if($complete['candidate_plans']!==50 || $complete['native_sales_handoffs']!==50
    || $complete['native_proposals_prepared']!==50
    || $complete['aggregate_state']!=='ready_for_independent_goal_evaluation'
    || $complete['criterion_threshold_observed']!==true
    || $complete['business_outcome_verified']!==false
    || $complete['verified_action_steps']['qualify']!==50){
    throw new RuntimeException('Goal native roll-up missed trusted fifty-candidate evidence or minted outcome.');
}

$onePending=$rows;
$onePending[49]['run_state']='pending';
$onePending[49]['verified_actions']=array_fill_keys(FederationGoalAggregateProjector::STAGES,false);
$onePending[49]['native_handoff_verified']=false;
$onePending[49]['native_proposal_verified']=false;
$onePending[49]['sales_lead_id']=null;
$onePending[49]['document_id']=null;
$partial=$p->project($goal,$onePending);
if($partial['native_proposals_prepared']!==49
    || $partial['aggregate_state']!=='in_progress_or_unverifiable'
    || $partial['criterion_threshold_observed']!==false) {
    throw new RuntimeException('A pending candidate was credited as a verified native Document.');
}
$duplicate=$rows;
$duplicate[49]['sales_lead_id']=$duplicate[0]['sales_lead_id'];
try{$p->project($goal,$duplicate);throw new RuntimeException('Duplicate Lead earned double credit.');}
catch(DomainException){}
$duplicate=$rows;
$duplicate[49]['document_id']=$duplicate[0]['document_id'];
try{$p->project($goal,$duplicate);throw new RuntimeException('Duplicate Document earned double credit.');}
catch(DomainException){}
$fake=$rows;
$fake[10]['verified_actions']['prepare']=false;
try{$p->project($goal,$fake);throw new RuntimeException('Native credit without complete Action chain.');}
catch(DomainException){}
$fake=$rows;
$fake[9]['plan_id']='plan-forged';
try{$p->project($goal,$fake);throw new RuntimeException('Cross-candidate Plan fingerprint was credited.');}
catch(DomainException){}
$fake=$rows;
$fake[5]['native_handoff_verified']=false;
try{$p->project($goal,$fake);throw new RuntimeException('Document attached to unproven Lead was credited.');}
catch(DomainException){}
$more=[...$rows,$rows[0]];
try{$p->project($goal,$more);throw new RuntimeException('51 candidates were accepted.');}
catch(DomainException){}
$other=new GoalSpecification(
    'goal-aggregate','tenant-aggregate','71','Close fifty sales',
    [['id'=>'sales.won_deals','operator'=>'at_least','expected'=>50]],
    ['growth.candidate.qualify'],
);
$notRevenue=$p->project($other,$rows);
if($notRevenue['criterion_target']!==null
    || $notRevenue['criterion_threshold_observed']!==false
    || $notRevenue['business_outcome_verified']!==false) {
    throw new RuntimeException('Prepared unsent proposals were promoted into won Sales deals.');
}
echo "Federation native Goal roll-up: 50 unique Leads/Documents and fail-closed negative proofs PASS.\n";
