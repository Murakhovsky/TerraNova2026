<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require (string)(getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php');
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;

$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',(string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),(string)(getenv('DB_DATABASE')?:'cos')),
 (string)(getenv('DB_USERNAME')?:'cos'),(string)(getenv('DB_PASSWORD')?:''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$products=new ProductManagementService(new MySqlProductManagementStore($pdo,new MysqlEventStore($pdo)));
$tag=bin2hex(random_bytes(6));$org='pm-attrs-'.$tag;$foreign='pm-foreign-'.$tag;$actor='42';$trace='pm-attrs-test';
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('Attribute acceptance: '.$why);};
$deny=static function(callable $fn,string $why):void{try{$fn();}catch(Throwable){return;}throw new RuntimeException('Unexpected acceptance: '.$why);};
$p=$products->createProduct($org,['name'=>'Furniture','sku'=>'ATTR'.$tag,'type'=>'stock'],'product',$actor,$trace);
$id=$p['product_id'];$category=$products->createCategory($org,'Furniture','furniture',null,'category',$actor,$trace)['category_id'];
$products->assignCategory($org,$id,$category,'assign',$actor,$trace);
$defs=[
 ['code'=>'material','name'=>'Material','value_type'=>'enum','choices'=>['oak','walnut']],
 ['code'=>'color','name'=>'Color','value_type'=>'enum','choices'=>['white','black']],
 ['code'=>'width','name'=>'Width','value_type'=>'decimal','unit'=>'mm'],
 ['code'=>'outdoor','name'=>'Outdoor','value_type'=>'boolean'],
 ['code'=>'notes','name'=>'Notes','value_type'=>'text'],
];
foreach($defs as $d){
 $receipt=$products->defineAttribute($org,$d,'def-'.$d['code'],$actor,$trace);
 $assert($products->defineAttribute($org,$d,'def-'.$d['code'],$actor,$trace)===$receipt,'definition replay');
 $products->bindAttribute($org,$category,$d['code'],$d['code']==='width',in_array($d['code'],['material','color'],true),'bind-'.$d['code'],$actor,$trace);
}
$assert(count($products->attributes($org))===5&&$products->attributes($foreign)===[],'dictionary tenant isolation');
$assert(count($products->categoryAttributes($org,$category))===5&&$products->categoryAttributes($foreign,$category)===[],'category tenant isolation');
$deny(fn()=>$products->defineAttribute($org,['code'=>'bad','name'=>'Bad','value_type'=>'enum','choices'=>['x','x']],'bad-def',$actor,$trace),'duplicate choice');
$before=$products->productAttributes($org,$id);$rev=$before['revision'];
$assert(count(array_filter($before['items'],fn($r)=>$r['missing_required']))===1,'required missing diagnostic');
$values=[['code'=>'width','value'=>'1200.5'],['code'=>'outdoor','value'=>false],['code'=>'material','value'=>'oak'],['code'=>'notes','value'=>'Custom']];
$deny(fn()=>$products->setAttributes($org,$id,[['code'=>'width','value'=>'12'],['code'=>'outdoor','value'=>'false']],$rev,'invalid-patch',$actor,$trace),'wrong boolean type');
$assert($products->productAttributes($org,$id)===$before,'invalid patch rolls back all values');
$after=$products->setAttributes($org,$id,$values,$rev,'attr-patch',$actor,$trace);
$assert($after===$products->productAttributes($org,$id)&&$after['revision']===$rev+1,'attribute native readback');
$assert($after===$products->setAttributes($org,$id,array_reverse($values),$rev,'attr-patch',$actor,$trace),'canonical patch replay');
$assert(count($products->filterAttributes($org,'width','1200.500000'))===1,'decimal normalization and exact filter');
$assert(count($products->filterAttributes($org,'outdoor',false))===1&&$products->filterAttributes($org,'outdoor',true)===[],'boolean filter');
$deny(fn()=>$products->setAttributes($org,$id,[['code'=>'width','value'=>null]],$rev+1,'required-remove',$actor,$trace),'required removal');
$deny(fn()=>$products->setAttributes($org,$id,[['code'=>'notes','value'=>'stale']],$rev,'stale',$actor,$trace),'revision fence');
$deny(fn()=>$products->setAttributes($foreign,$id,$values,$rev,'cross',$actor,$trace),'foreign product mutation');
$assert(!$products->productAttributes($foreign,$id)['found'],'foreign product read');
$rev=$after['revision'];$axes=[['code'=>'material','choices'=>['walnut','oak']],['code'=>'color','choices'=>['white','black']]];
$preview=$products->variantMatrix($org,$id,$axes,'MATRIX'.$tag);
$assert(count($preview['items'])===4&&$preview['revision']===$rev,'cartesian preview');
$assert($preview===$products->variantMatrix($org,$id,array_reverse($axes),'MATRIX'.$tag),'matrix deterministic');
$deny(fn()=>$products->variantMatrix($org,$id,[['code'=>'width','choices'=>['x']]],'BAD'),'non-enum axis');
$deny(fn()=>$products->variantMatrix($org,$id,[['code'=>'material','choices'=>['unknown']]],'BAD'),'unknown enum choice');
$deny(fn()=>$products->createMatrix($org,$id,$axes,'MATRIX'.$tag,$rev-1,'pcs',true,'stale-matrix',$actor,$trace),'stale matrix');
$m=$products->createMatrix($org,$id,$axes,'MATRIX'.$tag,$rev,'pcs',true,'matrix',$actor,$trace);
$assert(count($m['items'])===4&&$m['revision']===$rev+1,'atomic matrix creation');
$assert($m===$products->createMatrix($org,$id,array_reverse($axes),'MATRIX'.$tag,$rev,'pcs',true,'matrix',$actor,$trace),'matrix replay');
$fresh=$products->getProduct($org,$id);
$assert(count($fresh['variants'])===5,'original SKU preserved; four additional variants');
$assert(count(array_filter($products->variantMatrix($org,$id,$axes,'OTHER'.$tag)['items'],fn($r)=>$r['status']==='exists'))===4,'combination uniqueness independent of SKU prefix');
$deny(fn()=>$products->createMatrix($org,$id,$axes,'OTHER'.$tag,$rev+1,'pcs',true,'duplicate-matrix',$actor,$trace),'duplicate matrix');
$vid=$m['items'][0]['variant_id'];
$deny(fn()=>$products->updateVariant($org,$vid,['options'=>[['name'=>'color','value'=>'red']]],1,'mutate-identity',$actor,$trace),'managed identity immutable');
$loc=$products->createLocation($org,'ATTR-WH','Warehouse','location',$actor,$trace)['location_id'];
$products->recordMovement($org,$vid,$loc,'3','receipt','QA','stock',$actor,$trace);
$assert($products->balance($org,$vid,$loc)['on_hand']==='3.000000'&&$products->balance($org,$p['variant_id'],$loc)['on_hand']==='0.000000','independent stock identity');
$assert(count($products->getProduct($org,$id)['variants'])===5,'no duplicate writes');
$second=$products->createProduct($org,['name'=>'Second furniture','sku'=>'SECOND'.$tag,'type'=>'stock'],'second',$actor,$trace)['product_id'];
$products->assignCategory($org,$second,$category,'assign-second',$actor,$trace);
$r1=$products->productAttributes($org,$id);$r2=$products->productAttributes($org,$second);
$targets=[['product_id'=>$id,'expected_revision'=>$r1['revision']],['product_id'=>$second,'expected_revision'=>$r2['revision']]];
$stale=$targets;usort($stale,fn($a,$b)=>strcmp($a['product_id'],$b['product_id']));$stale[1]['expected_revision']++;
$patch=[['code'=>'notes','value'=>'Bulk changed']];
$deny(fn()=>$products->bulkSetAttributes($org,$stale,$patch,'stale-bulk',$actor,$trace),'stale second target');
$assert($products->productAttributes($org,$id)===$r1&&$products->productAttributes($org,$second)===$r2,'bulk rollback');
$bulk=$products->bulkSetAttributes($org,$targets,$patch,'bulk',$actor,$trace);
$assert(count($bulk['items'])===2&&$bulk===$products->bulkSetAttributes($org,array_reverse($targets),$patch,'bulk',$actor,$trace),'bulk exact replay');
$assert(count($products->filterAttributes($org,'notes','Bulk changed'))===2,'both bulk targets independently readable');
echo "Product Management typed attributes, category sets, filtering, bulk editing, revision and matrix native MySQL PASS.\n";
