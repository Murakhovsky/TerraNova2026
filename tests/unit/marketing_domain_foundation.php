<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
use Domains\Marketing\Domain\MarketingDraftPolicy;
use Domains\Marketing\Application\Service\PaidMediaMutationGate;
use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$assert=static function(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); };
$manifest=require $root.'/app/Domains/Marketing/module.php';
$assert($manifest['id']==='marketing'&&$manifest['enabled_by_default']===false,'Disabled marketing domain required');
$policy=new MarketingDraftPolicy();
$draft=$policy->validate(['name'=>'Autumn product campaign','business_objective'=>'Lead generation','product_refs'=>['P-1','P-1']]);
$assert($draft['product_refs']===['P-1'],'Product link deduplication');
foreach([
    ['name'=>'AB','business_objective'=>'leads'],
    ['name'=>'Valid name','business_objective'=>'go','organization_id'=>'forged'],
    ['name'=>'Valid name','business_objective'=>'go','status'=>'ACTIVE'],
    ['name'=>'Valid name','business_objective'=>'go','product_refs'=>['../../secret']],
] as $bad) {
    try { $policy->validate($bad); } catch(DomainException) { continue; }
    throw new RuntimeException('Invalid marketing draft was accepted');
}
try { (new PaidMediaMutationGate())->assertAllowed('campaign.activate'); }
catch(DomainException) { $blocked=true; }
$assert($blocked??false,'Paid mutations MUST fail closed');
$validator=new CapabilityJsonInputValidator();
$found=[];
foreach($manifest['contributions']['capability_contracts'] as $raw) {
    $contract=CapabilityContract::fromArray($raw);
    $found[$contract->id]=true;
    $assert(is_file($root.'/'.$contract->inputSchema),'Input schema missing: '.$contract->id);
    $assert(is_file($root.'/'.$contract->outputSchema),'Output schema missing: '.$contract->id);
}
$validator->validate(CapabilityContract::fromArray($manifest['contributions']['capability_contracts'][2]),['name'=>'Test launch','business_objective'=>'Leads']);
$assert(count($found)===5,'Expected five typed capabilities');
echo "Marketing M0 foundation PASS\n";

