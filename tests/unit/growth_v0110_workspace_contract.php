<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Provider\GrowthWebProvider;
use App\Web\Experience\Search\SearchResultMatcher;

function expectGrowthV0110(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$provider=new GrowthWebProvider(new SearchResultMatcher());
$context=new WebExtensionContext('org-1','manager','workspace','growth','growth-candidates');

$navigation=$provider->navigation($context);
expectGrowthV0110(count($navigation)===4,'Growth provider navigation contribution count changed.');
expectGrowthV0110($navigation[0]->key==='growth'&&$navigation[0]->path==='/growth','Growth root navigation is invalid.');
expectGrowthV0110($navigation[1]->parentKey==='growth','Growth child navigation must remain under Growth.');

$commands=$provider->commands($context);
expectGrowthV0110(count($commands)===3,'Growth command contribution count changed.');

$workspaces=$provider->workspaces($context);
expectGrowthV0110(array_map(static fn($item)=>$item->id,$workspaces)===[
    'growth.overview','growth.candidate','growth.account',
],'Growth workspace definitions changed unexpectedly.');

$search=$provider->search($context,'growth',10);
expectGrowthV0110($search!==[],'Growth provider search should resolve Growth workspace navigation.');

$views=[
    'growth/dashboard.phtml',
    'growth/candidates.phtml',
    'growth/candidate.phtml',
    'growth/accounts.phtml',
    'growth/account.phtml',
];
foreach($views as $view){
    expectGrowthV0110(is_file(dirname(__DIR__,2).'/app/Interfaces/Web/View/'.$view),'Growth Workspace view missing: '.$view);
}

echo "Growth V0.11 Workspace contracts passed.\n";
