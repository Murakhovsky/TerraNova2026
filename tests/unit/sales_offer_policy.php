<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\Sales\Domain\Policy\SalesOfferPolicy as Policy;
use Domains\Sales\Application\Support\SalesOfferSnapshot as Snapshot;
function verifyOffer(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function denyOffer(callable $f):void{try{$f();}catch(DomainException){return;}throw new RuntimeException('Expected denial');}
$input=['title'=>'Quote','currency'=>'USD','valid_until'=>'2035-01-01T02:00:00+02:00','lines'=>[['kind'=>'service','title'=>'Work','quantity'=>3,'unit_price'=>'10.01'],['kind'=>'product','title'=>'Goods','quantity'=>2,'unit_price'=>'99.99','product_id'=>'p','variant_id'=>'v']]];
$t=Policy::terms($input);verifyOffer($t['total']==='230.01'&&$t['valid_until']==='2035-01-01 00:00:00','Exact terms');
verifyOffer(Policy::transition('presented','accepted')&&!Policy::transition('draft','accepted')&&!Policy::transition('accepted','draft'),'State policy');
foreach([['kind'=>'service','title'=>'Overflow','quantity'=>2,'unit_price'=>'999999999999.99'],['kind'=>'property','title'=>'Asset','quantity'=>2,'unit_price'=>'1.00','inventory_id'=>'i'],['kind'=>'product','title'=>'Missing variant','quantity'=>1,'unit_price'=>'1.00','product_id'=>'p']] as $line)denyOffer(fn()=>Policy::terms(array_replace($input,['lines'=>[$line]])));
verifyOffer(Snapshot::digest(['b'=>2,'a'=>['z'=>1,'x'=>2]])===Snapshot::digest(['a'=>['x'=>2,'z'=>1],'b'=>2]),'Canonical digest');
echo "Sales offer policy passed\n";
