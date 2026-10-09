<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/vendor/autoload.php';

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Platform\Orchestration\Goal\FederationCandidateFanoutPlanner;
use Platform\Orchestration\Goal\GoalPlanValidator;
use Platform\Orchestration\Goal\GoalSpecification;

$root=dirname(__DIR__,2);
$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root.'/app/Domains'))->discover()
));
$allowed=['growth.market.discovery','growth.candidate.qualify',
    'growth.handoff.prepare','growth.handoff.target.sales','documents.proposal.prepare'];
$goal=new GoalSpecification('goal-one','org-one','42','Source and qualify candidates',
    [['id'=>'growth.qualified','operator'=>'at_least','expected'=>1]],$allowed);
$run=['organization_id'=>'org-one','run_id'=>'GMRN-1','universe_id'=>'U-1',
    'status'=>'completed','started_at'=>'2026-10-09 10:00:00.000000',
    'finished_at'=>'2026-10-09 10:20:00.000000'];
$members=[[
    'organization_id'=>'org-one','universe_id'=>'U-1','candidate_id'=>'C-1',
    'account_id'=>'A-1','external_key_hash'=>hash('sha256','source-A-1'),
    'source_reference'=>'source-A-1','last_seen_at'=>'2026-10-09 10:04:00.000000',
]];
$options=['policy_id'=>'P-1','policy_revision'=>1,'template_id'=>'T-1',
    'expected_value'=>'Opportunity value','recommended_play'=>'Call',
    'recommended_action'=>'Schedule consultation',
    'source_federation_run'=>'run-one','source_federation_step'=>'discover'];
$preview=(new FederationCandidateFanoutPlanner())->build(
    $goal,$run,$members,static fn(string $id):array=>[
        'organization_id'=>'org-one','candidate_id'=>$id,
        'subject_type'=>'account','subject_id'=>'A-1',
        'target_domain'=>'sales','status'=>'scored',
        'rationale'=>['evidence'=>'Source evidence'],'score'=>['total'=>75],
    ],1,$options
);
if (!$preview['ready'] || count($preview['plans'])!==1) throw new RuntimeException('Plan fixture was not ready.');
$plan=$preview['plans'][0];
$validator=new GoalPlanValidator($catalog);
$validated=$validator->validate($goal,$plan['steps'],$allowed);
if (!$validated['valid'] || count($validated['steps'])!==4) {
    throw new RuntimeException('Generated fan-out Plan does not pass canonical schema + capability validation: '
        .implode(';',$validated['errors']));
}
if ($validated['steps'][0]['depends_on']!==[]
    || $validated['steps'][1]['depends_on']!==['qualify']
    || $validated['steps'][2]['depends_on']!==['prepare']
    || $validated['steps'][3]['depends_on']!==['handoff']) {
    throw new RuntimeException('Fan-out workflow did not preserve immutable sequential predecessor gates.');
}
$bad=$plan['steps'];
$bad[0]['input']['parameters']['policy_revision']=0;
if ($validator->validate($goal,$bad,$allowed)['valid']) {
    throw new RuntimeException('Invalid candidate fan-out policy revision passed canonical contract.');
}
$bad=$plan['steps'];
$bad[3]['input']['target_type']='sales_lead';
if ($validator->validate($goal,$bad,$allowed)['valid']) {
    throw new RuntimeException('Future Lead ID substituted for approved Candidate.');
}
echo "Federation fan-out: four generated Steps pass canonical binding/JSON schema, DAG dependencies and negative tests PASS.\n";
