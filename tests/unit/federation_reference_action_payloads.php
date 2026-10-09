<?php
declare(strict_types=1);

require dirname(__DIR__,2) . '/vendor/autoload.php';

use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$validator = new CapabilityJsonInputValidator();
$cases = [
    ['growth', 'growth.market.discovery', [
        'target_type'=>'growth_market_universe', 'target_id'=>'GMU-1',
        'parameters'=>['limit'=>50],
    ]],
    ['growth', 'growth.candidate.qualify', [
        'target_type'=>'growth_candidate', 'target_id'=>'GC-1',
        'parameters'=>['policy_id'=>'GQPL-1','policy_revision'=>1],
    ]],
    ['growth', 'growth.handoff.prepare', [
        'target_type'=>'growth_candidate','target_id'=>'GC-1',
        'parameters'=>['expected_value'=>'25000 USD','recommended_play'=>'discovery',
            'recommended_action'=>'contact manager'],
    ]],
    ['growth', 'growth.handoff.target.sales', [
        'target_type'=>'growth_candidate','target_id'=>'GC-1',
        'parameters'=>['target_domain'=>'sales'],
    ]],
    ['documents', 'documents.proposal.prepare', [
        'target_type'=>'growth_candidate','target_id'=>'GC-1',
        'parameters'=>['template_id'=>'TPL-1','title'=>'Commercial offer'],
    ]],
];
$contracts=[];
foreach (['growth','documents'] as $domain) {
    $manifest = require dirname(__DIR__,2).'/app/Domains/'.ucfirst($domain).'/module.php';
    foreach ($manifest['contributions']['capability_contracts'] ?? [] as $item) {
        $contracts[$item['id']] = CapabilityContract::fromArray($item);
    }
}
foreach ($cases as [$domain,$id,$input]) {
    $contract=$contracts[$id]??throw new RuntimeException('Missing typed contract '.$id);
    if ($contract->ownerDomain !== $domain || $contract->executionBinding !== 'action:'.$id) {
        throw new RuntimeException('Noncanonical business Action binding: '.$id);
    }
    $validator->validate($contract,$input);
}
$invalid = [
    ['growth.market.discovery',['target_type'=>'growth_market_universe','target_id'=>'GMU-1',
        'parameters'=>['limit'=>201]]],
    ['growth.candidate.qualify',['target_type'=>'growth_candidate','target_id'=>'GC-1',
        'parameters'=>['policy_id'=>'GQPL-1','policy_revision'=>0]]],
    ['growth.handoff.prepare',['target_type'=>'growth_candidate','target_id'=>'GC-1',
        'parameters'=>['expected_value'=>'25000 USD','recommended_play'=>'discovery']]],
    ['growth.handoff.target.sales',['target_type'=>'growth_candidate','target_id'=>'GC-1',
        'parameters'=>['target_domain'=>'service']]],
    ['documents.proposal.prepare',['target_type'=>'sales_lead','target_id'=>'17',
        'parameters'=>['template_id'=>'TPL-1']]],
];
foreach ($invalid as [$id,$input]) {
    try {
        $validator->validate($contracts[$id],$input);
    } catch (DomainException) {
        continue;
    }
    throw new RuntimeException('Invalid immutable Action Plan input passed validation: '.$id);
}
echo "Federation reference Action input JSON contracts: five valid + five negative cases PASS.\n";
