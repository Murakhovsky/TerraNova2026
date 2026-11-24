<?php
declare(strict_types=1);
namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Automation\Action\ProductGovernedOperationHandler;
use Domains\ProductManagement\Domain\AttributeRules;
use Domains\ProductManagement\Domain\ProductRules;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\PricingService;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Only completed approved steps with exact native effects count. Never executes or repairs a mutation. */
final readonly class ProductGovernedRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION='product_management.governed_operations_verified';
    public function __construct(private Connection $db,private ProductManagementService $products,private PricingService $prices,
        private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'product_management';}
    public function supports(string $criterionId):bool{return $criterionId===self::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {throw new FederationOutcomeSourceNotReady('Product business outcome requires an attested Run.');}
    private function require(bool $ok,string $reason):void
    {
        if(!$ok)throw new DomainException('Product native evidence mismatch: '.$reason);
    }
    private static function decimal(string $value,int $scale):string
    {
        [$whole,$frac]=array_pad(explode('.',$value,2),2,'');
        return $whole.'.'.str_pad($frac,$scale,'0');
    }
    private function verifyNative(string $org,string $op,array $p,string $key,string $actor,array $r):array
    {
        $ids=[];
        switch($op){
            case 'product.create':
                $product=$this->products->getProduct($org,(string)($r['product_id']??''));
                $this->require($product!==null&&$product['name']===ProductRules::name($p['name'])
                    &&$product['product_type']===($p['type']??'stock'),'created Product');
                $variant=null;foreach($product['variants'] as $v)if($v['variant_id']===($r['variant_id']??null))$variant=$v;
                $this->require($variant!==null&&$variant['sku']===ProductRules::sku($p['sku'])&&$variant['uom']===($p['uom']??'pcs')
                    &&(bool)$variant['track_inventory']===($p['track_inventory']??in_array($p['type']??'stock',['stock','material'],true)),'created SKU');
                $ids=['product_management:product:'.$product['product_id'],'product_management:variant:'.$variant['variant_id']];
                break;
            case 'variant.matrix.create':
                $expected=AttributeRules::matrix($p['axes'],$p['sku_prefix']);
                $this->require(($r['product_id']??null)===$p['product_id']&&($r['revision']??null)===$p['expected_revision']+1&&count($r['items']??[])===count($expected),'matrix receipt');
                $expected=array_column($expected,null,'combination_hash');
                foreach($r['items'] as $item){
                    $hash=$item['combination_hash']??'';
                    $this->require(isset($expected[$hash])&&$item['sku']===$expected[$hash]['sku'],'matrix combination');
                    $v=$this->db->fetchAssociative('SELECT v.sku,v.uom,v.track_inventory,v.options_json,i.combination_hash FROM pm_variant_matrix_identities i JOIN pm_variants v ON v.organization_id=i.organization_id AND v.variant_id=i.variant_id AND v.product_id=i.product_id WHERE i.organization_id=? AND i.product_id=? AND i.variant_id=?',[$org,$p['product_id'],$item['variant_id']]);
                    $this->require($v!==false&&$v['sku']===$item['sku']&&$v['combination_hash']===$hash&&$v['uom']===($p['uom']??'pcs')&&(bool)$v['track_inventory']===($p['track_inventory']??true),'matrix native SKU');
                    $options=json_decode($v['options_json'],true,32,JSON_THROW_ON_ERROR);ksort($options,SORT_STRING);
                    $this->require($options===$expected[$hash]['options'],'matrix options');
                    unset($expected[$hash]);$ids[]='product_management:variant:'.$item['variant_id'];
                }
                $this->require($expected===[],'matrix missing SKU');
                break;
            case 'attribute.product.bulk_set':
                $targets=array_column($p['products'],'expected_revision','product_id');$patch=AttributeRules::changes($p['values']);
                $this->require(count($targets)===count($p['products'])&&count($r['items']??[])===count($targets),'bulk receipt size');
                foreach($r['items'] as $item){
                    $id=$item['product_id'];$this->require(isset($targets[$id])&&$item['revision']===$targets[$id]+1,'bulk target revision');
                    $fresh=$this->products->productAttributes($org,$id);
                    $this->require($fresh['found']&&$fresh['revision']>=$item['revision'],'bulk native revision');
                    $defs=array_column($fresh['items'],null,'code');$reported=array_column($item['values'], 'value','code');
                    $this->require(count($reported)===count($patch),'bulk value count');
                    foreach($patch as $code=>$value){
                        $this->require(isset($defs[$code])&&array_key_exists($code,$reported),'bulk attribute exists');
                        $normal=AttributeRules::value($defs[$code],$value);
                        $this->require($reported[$code]===$normal&&$defs[$code]['value']===$normal,'bulk approved value');
                    }
                    unset($targets[$id]);$ids[]='product_management:product:'.$id;
                }
                $this->require($targets===[],'bulk missing target');
                break;
            case 'price.base.set':
                $fresh=$this->prices->get($org,$p['variant_id'],$p['currency']);
                $this->require($fresh['revision']===$p['expected_revision']+1&&$fresh['price']===self::decimal($p['amount'],4)
                    &&$fresh==$r,'price revision and amount');
                $ids=['product_management:base_price:'.$p['variant_id'].':'.strtoupper($p['currency'])];
                break;
            case 'stock.receive':
            case 'stock.issue':
                $kind=$op==='stock.receive'?'receipt':'issue';$delta=($kind==='issue'?'-':'').$p['quantity'];
                $m=$this->db->fetchAssociative('SELECT movement_id,variant_id,location_id,quantity_delta,movement_type,source_ref,actor_ref FROM pm_stock_movements WHERE organization_id=? AND idempotency_key=?',[$org,$key]);
                $this->require($m!==false&&$m['movement_id']===($r['movement_id']??null)&&$m['variant_id']===$p['variant_id']&&$m['location_id']===$p['location_id']
                    &&$m['quantity_delta']===self::decimal($delta,6)&&$m['movement_type']===$kind&&$m['source_ref']===$p['source_ref']&&$m['actor_ref']===$actor,'immutable movement');
                $ids=['product_management:stock_movement:'.$m['movement_id']];
                break;
            case 'category.create':
                $id=$r['category_id']??null;
                $fresh=$this->db->fetchAssociative('SELECT name,slug,parent_id FROM pm_categories WHERE organization_id=? AND category_id=?',[$org,$id]);
                $this->require($fresh!==false&&$fresh['name']===ProductRules::name($p['name'])&&$fresh['slug']===ProductRules::slug($p['slug'])
                    &&$fresh['parent_id']===($p['parent_id']??null)&&$r['slug']===$fresh['slug']&&$r['parent_id']===$fresh['parent_id'],'category identity and parent');
                $ids=['product_management:category:'.$id];
                break;
            case 'category.assign':
                $this->require(($r['product_id']??null)===$p['product_id']&&($r['category_id']??null)===$p['category_id']
                    &&(int)$this->db->fetchOne('SELECT COUNT(*) FROM pm_product_categories WHERE organization_id=? AND product_id=? AND category_id=?',[$org,$p['product_id'],$p['category_id']])===1,'native category assignment');
                $ids=['product_management:product:'.$p['product_id'],'product_management:category:'.$p['category_id']];
                break;
            case 'translation.upsert':
                $fresh=$this->db->fetchAssociative('SELECT name,description FROM pm_product_translations WHERE organization_id=? AND product_id=? AND locale=?',[$org,$p['product_id'],$p['locale']]);
                $this->require($fresh!==false&&($r['product_id']??null)===$p['product_id']&&($r['locale']??null)===$p['locale']
                    &&$fresh['name']===ProductRules::name($p['name'])&&$fresh['description']===($p['description']??null)
                    &&$r['name']===$fresh['name']&&$r['description']===$fresh['description'],'native localized fields');
                $ids=['product_management:translation:'.$p['product_id'].':'.$p['locale']];
                break;
            case 'variant.create':
                $id=$r['variant_id']??null;
                $fresh=$this->db->fetchAssociative('SELECT product_id,sku,uom,track_inventory,status FROM pm_variants WHERE organization_id=? AND variant_id=?',[$org,$id]);
                $this->require($fresh!==false&&$fresh['product_id']===$p['product_id']&&$r['product_id']===$p['product_id']
                    &&$fresh['sku']===ProductRules::sku($p['sku'])&&$r['sku']===$fresh['sku']&&$fresh['uom']===($p['uom']??'pcs')
                    &&(bool)$fresh['track_inventory']===($p['track_inventory']??true)&&$fresh['status']==='active','native created variant');
                $ids=['product_management:variant:'.$id];
                break;
            case 'location.create':
                $id=$r['location_id']??null;
                $fresh=$this->db->fetchAssociative('SELECT code,name,status FROM pm_locations WHERE organization_id=? AND location_id=?',[$org,$id]);
                $this->require($fresh!==false&&$fresh['code']===ProductRules::code($p['code'])&&$r['code']===$fresh['code']
                    &&$fresh['name']===ProductRules::name($p['name'])&&$fresh['status']==='active','native warehouse location');
                $ids=['product_management:location:'.$id];
                break;
            case 'product.update':
            case 'product.archive':
                $id=$p['product_id'];
                $fresh=$this->db->fetchAssociative('SELECT name,description,brand,status,revision FROM pm_products WHERE organization_id=? AND product_id=?',[$org,$id]);
                $this->require($fresh!==false&&($r['product_id']??null)===$id&&($r['revision']??null)===$p['expected_revision']+1
                    &&(int)$fresh['revision']===$r['revision']&&$fresh['status']===($r['status']??null),'product lifecycle revision and status');
                if($op==='product.archive'){
                    $this->require($fresh['status']==='archived','product archived');
                    $this->require((int)$this->db->fetchOne('SELECT COUNT(*) FROM pm_stock_balances b JOIN pm_variants v ON v.organization_id=b.organization_id AND v.variant_id=b.variant_id WHERE b.organization_id=? AND v.product_id=? AND (b.on_hand<>0 OR b.reserved<>0)',[$org,$id])===0,'archived product stock');
                }else{
                    $this->require($fresh['status']!=='archived','product update status');
                    foreach(['name','description','brand'] as $field)if(array_key_exists($field,$p))
                        $this->require($fresh[$field]===($field==='name'?ProductRules::name($p[$field]):trim($p[$field])),'product updated '.$field);
                }
                $ids=['product_management:product:'.$id];
                break;
            case 'variant.update':
            case 'variant.archive':
                $id=$p['variant_id'];
                $fresh=$this->db->fetchAssociative('SELECT sku,uom,barcode,options_json,status,revision FROM pm_variants WHERE organization_id=? AND variant_id=?',[$org,$id]);
                $this->require($fresh!==false&&($r['variant_id']??null)===$id&&($r['revision']??null)===$p['expected_revision']+1
                    &&(int)$fresh['revision']===$r['revision']&&$fresh['status']===($r['status']??null),'variant lifecycle revision and status');
                if($op==='variant.archive'){
                    $this->require($fresh['status']==='archived','variant archived');
                    $this->require((int)$this->db->fetchOne('SELECT COUNT(*) FROM pm_stock_balances WHERE organization_id=? AND variant_id=? AND (on_hand<>0 OR reserved<>0)',[$org,$id])===0,'archived variant stock');
                }else{
                    $this->require($fresh['status']==='active','variant update status');
                    if(array_key_exists('sku',$p))$this->require($fresh['sku']===ProductRules::sku($p['sku']),'variant SKU');
                    if(array_key_exists('uom',$p))$this->require($fresh['uom']===$p['uom'],'variant UOM');
                    if(array_key_exists('barcode',$p))$this->require($fresh['barcode']===($p['barcode']===''?null:$p['barcode']),'variant barcode');
                    if(array_key_exists('options',$p)){
                        $expected=array_column($p['options'],'value','name');
                        $actual=json_decode((string)$fresh['options_json'],true,32,JSON_THROW_ON_ERROR);
                        $this->require(is_array($actual)&&$actual==$expected,'variant options');
                    }
                }
                $ids=['product_management:variant:'.$id];
                break;
            case 'stock.transfer':
                $this->require(($r['variant_id']??null)===$p['variant_id']&&($r['from_location_id']??null)===$p['from_location_id']
                    &&($r['to_location_id']??null)===$p['to_location_id']&&($r['source_ref']??null)===$p['source_ref']
                    &&($r['quantity']??null)===$p['quantity']&&$p['from_location_id']!==$p['to_location_id'],'transfer receipt');
                $digest=hash('sha256',$org.':'.$key);
                foreach([['out','issue','-'.$p['quantity'],$p['from_location_id']],['in','receipt',$p['quantity'],$p['to_location_id']]] as [$direction,$kind,$delta,$location]){
                    $m=$this->db->fetchAssociative('SELECT movement_id,variant_id,location_id,quantity_delta,movement_type,source_ref,actor_ref FROM pm_stock_movements WHERE organization_id=? AND idempotency_key=?',[$org,'pm-xfer-'.$direction.':'.$digest]);
                    $this->require($m!==false&&$m['movement_id']===($r[$direction.'_movement_id']??null)&&$m['variant_id']===$p['variant_id']
                        &&$m['location_id']===$location&&$m['quantity_delta']===self::decimal($delta,6)&&$m['movement_type']===$kind
                        &&$m['source_ref']===$p['source_ref']&&$m['actor_ref']===$actor,'paired transfer ledger '.$direction);
                    $ids[]='product_management:stock_movement:'.$m['movement_id'];
                }
                $this->require($ids[0]!==$ids[1],'distinct transfer movements');
                break;
            case 'price.list.create':
                $id=$r['list_id']??null;
                $fresh=$this->db->fetchAssociative('SELECT list_id,code,name,kind,currency,status,revision FROM pm_price_lists WHERE organization_id=? AND list_id=?',[$org,$id]);
                $this->require($fresh!==false&&$fresh['code']===ProductRules::code($p['code'])&&$fresh['name']===ProductRules::name($p['name'])
                    &&$fresh['kind']===$p['kind']&&$fresh['currency']===strtoupper($p['currency'])&&$fresh['status']==='active'&&(int)$fresh['revision']===1,'native created price list');
                foreach(['list_id','code','name','kind','currency'] as $field)$this->require(($r[$field]??null)===$fresh[$field],'price list receipt '.$field);
                $ids=['product_management:price_list:'.$id];
                break;
            case 'price.list.update':
            case 'price.list.archive':
                $id=$p['list_id'];
                $fresh=$this->db->fetchAssociative('SELECT list_id,code,name,kind,currency,status,revision FROM pm_price_lists WHERE organization_id=? AND list_id=?',[$org,$id]);
                $this->require($fresh!==false,'native price list exists');
                $fresh['revision']=(int)$fresh['revision'];
                $this->require($fresh==$r&&$fresh['revision']===$p['expected_revision']+1
                    &&$fresh['status']===($op==='price.list.archive'?'archived':'active'),'price list revision and lifecycle');
                if($op==='price.list.update')$this->require($fresh['name']===ProductRules::name($p['name']),'price list name');
                $ids=['product_management:price_list:'.$id];
                break;
            case 'price.list.entry.remove':
                $fresh=$this->prices->getListPrice($org,$p['list_id'],$p['variant_id']);
                $revision=$p['expected_revision']+1;
                $history=$this->db->fetchAssociative('SELECT old_amount,new_amount,actor_ref FROM pm_price_list_history WHERE organization_id=? AND list_id=? AND variant_id=? AND revision=?',[$org,$p['list_id'],$p['variant_id'],$revision]);
                $this->require($fresh['found']&&$fresh['revision']===$revision&&$fresh['price']===null&&$fresh==$r
                    &&$history!==false&&$history['old_amount']!==null&&$history['new_amount']===null&&$history['actor_ref']===$actor,'removed price and history');
                $ids=['product_management:list_price:'.$p['list_id'].':'.$p['variant_id'].':'.$revision];
                break;
            case 'price.list.tier.set':
                $minimum=self::decimal($p['min_quantity'],6);$revision=$p['expected_revision']+1;
                $amount=$p['amount']===null?null:self::decimal($p['amount'],4);
                $fresh=$this->prices->tiers($org,$p['list_id'],$p['variant_id'],1,0,$minimum)[0]??null;
                $history=$this->db->fetchAssociative('SELECT old_amount,new_amount,actor_ref FROM pm_price_list_tier_history WHERE organization_id=? AND list_id=? AND variant_id=? AND min_quantity=? AND revision=?',[$org,$p['list_id'],$p['variant_id'],$minimum,$revision]);
                $this->require($fresh!==null&&$fresh==$r&&$fresh['revision']===$revision&&$fresh['price']===$amount
                    &&$fresh['min_quantity']===$minimum&&$history!==false&&$history['new_amount']===$amount&&$history['actor_ref']===$actor
                    &&($amount!==null||$history['old_amount']!==null),'tier threshold, price and history');
                $ids=['product_management:list_tier:'.$p['list_id'].':'.$p['variant_id'].':'.$minimum.':'.$revision];
                break;
            case 'price.list.entry.set':
                $fresh=$this->prices->getListPrice($org,$p['list_id'],$p['variant_id']);
                $revision=$p['expected_revision']+1;
                $amount=self::decimal($p['amount'],4);
                $history=$this->db->fetchAssociative('SELECT new_amount,actor_ref FROM pm_price_list_history WHERE organization_id=? AND list_id=? AND variant_id=? AND revision=?',[$org,$p['list_id'],$p['variant_id'],$revision]);
                $this->require($fresh['found']&&$fresh['revision']===$revision&&$fresh['price']===$amount&&$fresh==$r
                    &&$history!==false&&$history['new_amount']===$amount&&$history['actor_ref']===$actor,'list price revision and history');
                $ids=['product_management:list_price:'.$p['list_id'].':'.$p['variant_id'].':'.$revision];
                break;
            case 'stock.reserve':
            case 'stock.release':
                $id=$r['reservation_id']??null;
                $reservation=$this->db->fetchAssociative('SELECT variant_id,location_id,quantity,source_ref,status,actor_ref,release_reason FROM pm_stock_reservations WHERE organization_id=? AND reservation_id=?',[$org,$id]);
                $this->require($reservation!==false&&$reservation['variant_id']===($r['variant_id']??null)&&$reservation['location_id']===($r['location_id']??null)
                    &&$reservation['source_ref']===($r['source_ref']??null)&&$reservation['quantity']===self::decimal((string)($r['quantity']??''),6),'native reservation');
                if($op==='stock.reserve'){
                    $this->require(($r['status']??null)==='active'&&$reservation['variant_id']===$p['variant_id']&&$reservation['location_id']===$p['location_id']
                        &&$reservation['quantity']===self::decimal($p['quantity'],6)&&$reservation['source_ref']===$p['source_ref']&&$reservation['actor_ref']===$actor,'approved reserve');
                }else{
                    $this->require($id===$p['reservation_id']&&($r['status']??null)==='released'&&$reservation['status']==='released'
                        &&$reservation['release_reason']===trim($p['reason']),'approved release');
                }
                $ids=['product_management:stock_reservation:'.$id];
                break;
            default:throw new DomainException('Unsupported governed operation evidence.');
        }
        return $ids;
    }
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid Product business criterion.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.input_digest,w.state,w.actor_id,w.definition_json,s.step_id,s.state AS step_state,s.capability_id,s.result_reference,s.idempotency_key
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            WHERE w.organization_id=? AND w.run_id=? AND w.definition_id=? ORDER BY s.ordinal_no',[$org,$runId,'federation.approved-plan']);
        if($rows===[])throw new FederationOutcomeSourceNotReady('Completed Product Run required.');
        $graph=json_decode($rows[0]['definition_json'],true,64,JSON_THROW_ON_ERROR);
        $steps=array_column($graph,null,'id');$evidence=[];$verified=0;
        foreach($rows as $row){
            if($row['state']!=='completed'||$row['step_state']!=='completed')throw new FederationOutcomeSourceNotReady('Run is not complete.');
            $cap=$row['capability_id'];
            if(!in_array($cap,ProductGovernedOperationHandler::types(),true))continue;
            $step=$steps[$row['step_id']]??null;
            $this->require(is_array($step)&&$step['capability_id']===$cap&&$step['input']['target_type']==='product_governed_operation','pinned operation');
            if(!$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$row['step_id'],(string)$row['result_reference'],$row['input_digest'])->verified)
                throw new DomainException('Canonical Product Action attestation failed.');
            $op=substr($cap,strlen(ProductGovernedOperationHandler::PREFIX));$key='wf:'.$row['idempotency_key'];
            $native=$this->db->fetchAssociative('SELECT operation,result_json,created_at FROM pm_operation_receipts WHERE organization_id=? AND idempotency_key=?',[$org,$key]);
            $this->require($native!==false&&$native['operation']===ProductGovernedOperationHandler::OPERATIONS[$op]&&is_string($native['result_json']),'native receipt');
            $created=new DateTimeImmutable($native['created_at'],new DateTimeZone('UTC'));
            $this->require($created>=$from&&$created<=$to,'native receipt outside evaluation window');
            $result=json_decode($native['result_json'],true,64,JSON_THROW_ON_ERROR);
            $ids=$this->verifyNative($org,$op,$step['input']['parameters'],$key,$row['actor_id'],$result);
            $evidence[]='product_management:operation_receipt:'.$key;
            array_push($evidence,...$ids);$verified++;
        }
        if($verified===0)throw new FederationOutcomeSourceNotReady('No governed Product operation in Run.');
        return ['value'=>$verified,'evidence'=>array_values(array_unique($evidence)),'source'=>'product_management.governed_operations.v1',
            'window_start'=>$from->format('Y-m-d\TH:i:s.uP'),'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
