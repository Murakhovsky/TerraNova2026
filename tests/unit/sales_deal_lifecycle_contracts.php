<?php
declare(strict_types=1);

use Domains\Sales\Domain\Policy\LeadLifecyclePolicy;
use Domains\Sales\Model\DealChangeSet;
use Domains\Sales\Application\Support\DealStateSnapshot;

require dirname(__DIR__,2).'/vendor/autoload.php';
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$deny=static function(callable $fn):void{try{$fn();}catch(InvalidArgumentException|DomainException){return;}throw new RuntimeException('Invalid business state accepted.');};
$check(DealChangeSet::fromArray(['deal_value'=>'123456789012.34','currency'=>'EUR'])->toArray()===['deal_value'=>'123456789012.34','currency'=>'EUR'],'Decimal precision lost.');
foreach([['deal_value'=>'bad','currency'=>'USD'],['deal_value'=>'1.01'],['currency'=>'USD'],['deal_value'=>1.01,'currency'=>'USD'],['deal_value'=>'1234567890123.00','currency'=>'USD'],['deal_value'=>'-1.00','currency'=>'EUR']] as $input)$deny(fn()=>DealChangeSet::fromArray($input));
foreach(['won','lost','spam','disqualified','closed'] as $status)foreach(['new','contacted','qualified','viewing','negotiation','lost','won'] as $next)$check(!LeadLifecyclePolicy::allows($status,$next),'Terminal reopened.');
$check(LeadLifecyclePolicy::allows('qualified','viewing_planned') && LeadLifecyclePolicy::allows('viewing','negotiation') && LeadLifecyclePolicy::allows('negotiation','won'),'Lifecycle edges missing.');
$row=['id'=>7,'organization_id'=>'tenant-a','pipeline_id'=>'p','stage_id'=>'s','status'=>'active','assigned_user_id'=>3,'deal_value'=>'100.00','currency'=>'EUR','lost_reason_id'=>null,'lost_reason_note'=>'private'];
$a=DealStateSnapshot::fromNative($row,'tenant-a',7);
$check(!isset($a['lost_reason_note']) && $a['revision']!==DealStateSnapshot::fromNative([...$row,'currency'=>'USD'],'tenant-a',7)['revision'],'Revision or redaction failed.');
$deny(fn()=>DealStateSnapshot::fromNative($row,'tenant-b',7));
echo "Sales lifecycle and commercial model contracts PASS.\n";
