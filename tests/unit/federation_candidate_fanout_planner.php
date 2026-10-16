<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/vendor/autoload.php';

use Platform\Orchestration\Goal\FederationCandidateFanoutPlanner;
use Platform\Orchestration\Goal\GoalSpecification;

$planner=new FederationCandidateFanoutPlanner();
$goal=new GoalSpecification(
    'goal-1','tenant-one','manager-1','Find 50 verified leads',
    [['id'=>'growth.qualified','operator'=>'at_least','expected'=>50]],
    ['growth.market.discovery','growth.candidate.qualify','growth.handoff.prepare',
     'growth.handoff.target.sales','documents.proposal.prepare'],
);
$native=['organization_id'=>'tenant-one','run_id'=>'GMRN-A1','universe_id'=>'U-1',
    'status'=>'completed','started_at'=>'2026-10-09 10:00:00.000000',
    'finished_at'=>'2026-10-09 10:15:00.000000'];
$member=static fn(string $id, string $account, string $when='2026-10-09 10:05:00.000000'):array=>[
    'organization_id'=>'tenant-one','universe_id'=>'U-1','candidate_id'=>$id,
    'account_id'=>$account,'external_key_hash'=>hash('sha256',$account),
    'source_reference'=>'https://example.test/company/'.$account,
    'last_seen_at'=>$when,
];
$memberships=[$member('C-2','account-2'),$member('C-1','account-1'),$member('C-1','account-1')];
$view=static fn(string $id):?array=>[
    'organization_id'=>'tenant-one','candidate_id'=>$id,
    'subject_type'=>'account','subject_id'=>$id==='C-1'?'account-1':'account-2',
    'target_domain'=>'sales','status'=>'scored',
    'score'=>['total'=>80],'rationale'=>['evidence'=>'trusted'],
];
$opt=['policy_id'=>'POL-1','policy_revision'=>2,'template_id'=>'T-1',
    'expected_value'=>'500 USD','recommended_play'=>'consultation',
    'recommended_action'=>'call decision maker',
    'source_federation_run'=>'run-parent','source_federation_step'=>'discovery'];
$a=$planner->build($goal,$native,$memberships,$view,2,$opt);
$b=$planner->build($goal,$native,array_reverse($memberships),$view,2,$opt);
if(!$a['ready'] || $a['eligible']!==2 || $a['proposed_count']!==2
    || $a['plans']!==$b['plans'] || $a['business_outcome_verified']!==false) {
    throw new RuntimeException('Deterministic 2-candidate fan-out failed.');
}
// A second membership for the same account/candidate with a different
// source may be returned first after refresh; preview lineage must be
// independent of database row order.
$conflicting = array_replace($member('C-1','account-1'),[
    'source_reference'=>'https://second-source.test/account-1',
    'external_key_hash'=>hash('sha256','alternative source'),
]);
$ordered = [$conflicting, ...$memberships];
$reverse = array_reverse($ordered);
$choiceA=$planner->build($goal,$native,$ordered,$view,2,$opt);
$choiceB=$planner->build($goal,$native,$reverse,$view,2,$opt);
if ($choiceA['plans']!==$choiceB['plans']) {
    throw new RuntimeException('Fan-out lineage changed with membership row order.');
}

// Repeated discovery of the same tenant/Goal/Candidate must NOT mint a
// second executable Plan identity with a fresh native source run.
$repeat=$planner->build($goal,array_replace($native,['run_id'=>'GMRN-NEXT']),
    $memberships,$view,2,$opt);
if ($repeat['plans'][0]['plan_id']!==$a['plans'][0]['plan_id']
    || $repeat['plans'][0]['lineage']['native_discovery_run']===$a['plans'][0]['lineage']['native_discovery_run']) {
    throw new RuntimeException('Repeated source scan can duplicate candidate Plan or loses attested lineage.');
}
$first=$a['plans'][0];
if($first['candidate_id']!=='C-1'
    || count($first['steps'])!==4
    || $first['steps'][0]['capability_id']!=='growth.candidate.qualify'
    || $first['steps'][3]['input']['target_id']!=='C-1'
    || $first['steps'][3]['input']['parameters']['template_id']!=='T-1'
    || $first['steps'][3]['input']['parameters']['variables']['candidate_id']!=='C-1'
    || $first['steps'][3]['input']['parameters']['variables']['account_id']!=='account-1'
    || $first['steps'][3]['input']['parameters']['variables']['expected_value']!=='500 USD'
    || $first['lineage']['source_federation_run']!=='run-parent'
    || $first['lineage']['native_discovery_run']!=='GMRN-A1') {
    throw new RuntimeException('Approved native Candidate subplan/lineage incorrect.');
}
$notReady=$planner->build($goal,$native,$memberships,$view,3,$opt);
if($notReady['ready'] || $notReady['proposed_count']!==0
    || $notReady['eligible']!==2 || $notReady['plans']!==[]) {
    throw new RuntimeException('Insufficient candidates silently reduced requested target.');
}
$stale=$planner->build($goal,$native,[$member('C-1','account-1','2026-10-09 11:00:00')],$view,1,$opt);
if($stale['ready'] || $stale['eligible']!==0) {
    throw new RuntimeException('Membership outside attested discovery window accepted.');
}
$unscored=$planner->build($goal,$native,[$member('C-1','account-1')],
    static fn(string $id):array=>['organization_id'=>'tenant-one','candidate_id'=>$id,'status'=>'detected'],1,$opt);
if($unscored['ready']) throw new RuntimeException('Unscored candidate accepted as qualified.');
$nonCandidate=$planner->build($goal,$native,[array_replace($member('C-1','account-1'),['candidate_id'=>null])],$view,1,$opt);
if($nonCandidate['ready']) throw new RuntimeException('Account-only discovery accepted as candidate.');
foreach([0,51] as $size) {
    try { $planner->build($goal,$native,$memberships,$view,$size,$opt); }
    catch(DomainException){continue;}
    throw new RuntimeException('Unsafe fan-out size accepted.');
}
try {
    $planner->build($goal,$native,[array_replace($member('C-1','account-1'),['organization_id'=>'other'])],$view,1,$opt);
    throw new RuntimeException('Cross-tenant source accepted.');
} catch(DomainException) {}
try {
    $planner->build($goal,$native,$memberships,$view,1,array_replace($opt,['policy_revision'=>0]));
    throw new RuntimeException('Unversioned policy accepted.');
} catch(DomainException) {}
echo "Federation fan-out: stable 1..50 proposals, scored/source evidence, no silent partial target, no cross-tenant PASS.\n";
