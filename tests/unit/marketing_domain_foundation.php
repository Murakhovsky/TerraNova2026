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
$assert(count($found)===43,'Expected 42 typed Marketing contracts including Commerce Attribution');
echo "Marketing M0 foundation PASS\n";


$attr=\Domains\Marketing\Domain\MarketingAttributionPolicy::class;
$campaign='mkt-'.str_repeat('a',24);$plan='mkpl-'.str_repeat('b',24);
$lead=['lead_id'=>'42','created_at'=>'2026-01-01 10:00:00','utm_campaign'=>$plan,'utm_source'=>'meta','utm_medium'=>'paid_social'];
$e=$attr::evidence($lead,$campaign,$plan);
$assert($e['model']==='utm-exact-v1'&&$e['evidence_hash']===$attr::evidence($lead,$campaign,$plan)['evidence_hash'],'Deterministic attribution evidence required.');
foreach([[...$lead,'utm_campaign'=>$campaign],[...$lead,'utm_source'=>'google'],[...$lead,'utm_medium'=>'organic'],[...$lead,'lead_id'=>'../../x']] as $bad){
    $blocked=false;try{$attr::evidence($bad,$campaign,$plan);}catch(DomainException){$blocked=true;}
    $assert($blocked,'Wrong native attribution tag accepted.');
}
$assert($attr::minor('12.340000')===1234&&$attr::minor('12.345')===null&&$attr::ratio(5,0)===null,'Currency precision or zero denominator unsafe.');
$metrics=$attr::media(['id'=>'mis-'.str_repeat('c',24),'revision'=>2,'source_status'=>'REVISED','fetched_at'=>'2026-01-01','currency'=>'USD',
    'spend_decimal'=>'12.50','metrics'=>['impressions'=>'1000','clicks'=>'25']]);
$assert($metrics['ctr_percent']===2.5&&$metrics['cpc']===0.5&&$metrics['cpm']===12.5,'M4 media metric formulas incorrect.');
echo "Marketing M4 exact attribution and guarded KPI policy PASS\n";

$mp=\Domains\Marketing\Domain\MarketingMediaAnalyticsPolicy::class;
$a=['ad_account_id'=>'act_123','currency'=>'USD','timezone'=>'UTC'];
$rows=$mp::sanitize($a,'campaign','2026-01-01','2026-01-03',[['account_id'=>'123','campaign_id'=>'456','date_start'=>'2026-01-01','date_stop'=>'2026-01-03','spend'=>'1.234567','impressions'=>'100','clicks'=>'2']]);
$assert(count($rows)===1&&$mp::micro('1.234567')===1234567,'Exact media decimal lost.');
foreach([[...$rows,$rows[0]],[[...$rows[0],'account_id'=>'124']],[[...$rows[0],'spend'=>null]]] as $bad){
    $blocked=false;try{$mp::sanitize($a,'campaign','2026-01-01','2026-01-03',$bad);}catch(DomainException){$blocked=true;}
    $assert($blocked,'Unsafe media batch admitted.');
}
$sum=$mp::summarize([['reported_spend_decimal'=>'1.234567','impressions'=>100,'clicks'=>2],['reported_spend_decimal'=>'2.000001','impressions'=>null,'clicks'=>1]]);
$assert($sum['reported_spend_decimal']==='3.234568'&&$sum['impressions']===null&&$sum['clicks']===3&&$sum['reach']===null,'Unknown metric or exact aggregate unsafe.');
echo "Marketing M4 complete media batch validation and exact aggregate PASS\n";
