<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Platform\Orchestration\Goal\FederationCandidateFanoutPlanner;
use Platform\Orchestration\Goal\GoalSpecification;

$planner = new FederationCandidateFanoutPlanner();
$goal = new GoalSpecification(
    'goal-50', 'tenant-50', '71', 'Source fifty real candidates',
    [['id'=>'growth.qualified','operator'=>'at_least','expected'=>50]],
    ['growth.candidate.qualify','growth.handoff.prepare',
        'growth.handoff.target.sales','documents.proposal.prepare'],
);
$run = [
    'organization_id'=>'tenant-50', 'run_id'=>'GMRN-FIFTY',
    'universe_id'=>'U-50','status'=>'completed',
    'started_at'=>'2026-10-09 10:00:00.000000',
    'finished_at'=>'2026-10-09 10:10:00.000000',
];
$members = [];
for ($i=1;$i<=50;$i++) {
    $id = sprintf('%03d',$i);
    $members[]=[
        'organization_id'=>'tenant-50',
        'universe_id'=>'U-50',
        'candidate_id'=>'C-'.$id,
        'account_id'=>'A-'.$id,
        'external_key_hash'=>hash('sha256','source-'.$id),
        'source_reference'=>'https://example.test/account/'.$id,
        'last_seen_at'=>'2026-10-09 10:05:00.000000',
        'account_name'=>'Account '.$id,
    ];
}
$view = static fn (string $id): ?array => [
    'organization_id'=>'tenant-50',
    'candidate_id'=>$id,
    'subject_type'=>'account',
    'subject_id'=>str_replace('C-','A-',$id),
    'target_domain'=>'sales',
    'status'=>'scored',
    'rationale'=>['observations'=>['trusted source']],
    'score'=>['total'=>78],
    'lead_name'=>'Champion '.$id,
    'lead_email'=>strtolower($id).'@example.test',
];
$opts=[
    'policy_id'=>'POL-1','policy_revision'=>2,'template_id'=>'TMPL-1',
    'expected_value'=>'USD 1000','recommended_play'=>'Consultation',
    'recommended_action'=>'Book introductory call',
    'source_federation_run'=>'run-master','source_federation_step'=>'discovery',
];
$one = $planner->build($goal,$run,$members,$view,10,$opts);
if (!$one['ready'] || count($one['plans'])!==10 || $one['existing_count']!==0) {
    throw new RuntimeException('First 10 candidates were not proposed.');
}
$prior = array_column($one['plans'],'candidate_id');
$two = $planner->build($goal,$run,array_reverse($members),$view,40,$opts,$prior);
$all = [...$one['plans'],...$two['plans']];
if (!$two['ready'] || count($two['plans'])!==40
    || $two['goal_remaining']!==40
    || $two['existing_count']!==10
    || count(array_unique(array_column($all,'candidate_id')))!==50
    || count(array_unique(array_column($all,'plan_id')))!==50) {
    throw new RuntimeException('10+40 fan-out failed idempotent Goal candidate coverage.');
}
if ($one['plans'][0]['candidate_id']!=='C-001'
    || $two['plans'][0]['candidate_id']!=='C-011'
    || $two['plans'][39]['candidate_id']!=='C-050'
    || array_unique(array_column($two['plans'],'candidate_id')) === []) {
    throw new RuntimeException('Deterministic incremental ordering drift.');
}
try {
    $planner->build($goal,$run,$members,$view,1,$opts,array_column($all,'candidate_id'));
    throw new RuntimeException('Accepted a 51st Goal Candidate.');
} catch(DomainException) {
}
try {
    $planner->build($goal,$run,$members,$view,41,$opts,$prior);
    throw new RuntimeException('Allowed requested fan-out to exceed Goal remainder.');
} catch(DomainException) {
}
try {
    $planner->build($goal,$run,$members,$view,1,$opts,['C-001','C-001']);
    throw new RuntimeException('Duplicate exclusion identity was accepted.');
} catch(DomainException) {
}
$insufficient = $planner->build($goal,$run,array_slice($members,0,20),
    $view,15,$opts,$prior);
if ($insufficient['ready'] || $insufficient['plans']!==[]
    || $insufficient['eligible']!==10) {
    throw new RuntimeException('Incremental plan wrongly accepts partial batch.');
}
echo "Federation incremental 50: 10 + 40 unique candidate plans, cap and replay guards PASS.\n";
