<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Provider\GrowthWebProvider;
use App\Web\Experience\Search\SearchResultMatcher;

function expectGrowthV0120(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$provider=new GrowthWebProvider(new SearchResultMatcher());
$context=new WebExtensionContext('org-1','manager','workspace','growth','growth-collectors');

$navigation=$provider->navigation($context);
expectGrowthV0120(count($navigation)>=6,'Growth provider lost V0.12 navigation contributions.');
$keys=array_map(static fn($item)=>$item->key,$navigation);
foreach(['growth','growth-overview','growth-candidates','growth-accounts','growth-signals','growth-collectors'] as $key){
    expectGrowthV0120(in_array($key,$keys,true),'Growth V0.12 navigation missing: '.$key);
}

$commands=$provider->commands($context);
expectGrowthV0120(count($commands)>=5,'Growth provider lost V0.12 commands.');

$workspaces=$provider->workspaces($context);
$ids=array_map(static fn($item)=>$item->id,$workspaces);
foreach(['growth.overview','growth.candidate','growth.account','growth.signals','growth.collectors'] as $id){
    expectGrowthV0120(in_array($id,$ids,true),'Growth provider lost V0.12 workspace: '.$id);
}

$search=$provider->search($context,'collector',10);
expectGrowthV0120($search!==[]&&$search[0]->path==='/growth/collectors','Growth collector search result is missing.');

echo "Growth V0.12 Signal Operations contracts passed.\n";
