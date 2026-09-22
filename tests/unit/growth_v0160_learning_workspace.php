<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Provider\GrowthWebProvider;
use App\Web\Experience\Search\SearchResultMatcher;

function expectGrowthV0160(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$provider=new GrowthWebProvider(new SearchResultMatcher());
$context=new WebExtensionContext('org-1','manager','workspace','growth','growth-learning');

$navigation=$provider->navigation($context);
expectGrowthV0160(count($navigation)===7,'Growth V0.16 navigation contribution count changed.');
$keys=array_map(static fn($item)=>$item->key,$navigation);
foreach(['growth','growth-overview','growth-candidates','growth-accounts','growth-signals','growth-collectors','growth-learning'] as $key){
    expectGrowthV0160(in_array($key,$keys,true),'Growth V0.16 navigation missing: '.$key);
}

$commands=$provider->commands($context);
expectGrowthV0160(count($commands)===6,'Growth V0.16 command contribution count changed.');
$commandIds=array_map(static fn($item)=>$item->id,$commands);
expectGrowthV0160(in_array('growth.learning',$commandIds,true),'Growth Learning command missing.');

$workspaces=$provider->workspaces($context);
expectGrowthV0160(count($workspaces)===6,'Growth V0.16 workspace contribution count changed.');
$ids=array_map(static fn($item)=>$item->id,$workspaces);
expectGrowthV0160(in_array('growth.learning',$ids,true),'Growth Learning workspace definition missing.');

$search=$provider->search($context,'learning',10);
expectGrowthV0160($search!==[]&&$search[0]->path==='/growth/learning','Growth Learning search result is missing.');

echo "Growth V0.16 Learning Workspace contracts passed.\n";
