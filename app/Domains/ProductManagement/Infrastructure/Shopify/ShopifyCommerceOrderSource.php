<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use DateTimeImmutable;
use DateTimeZone;
use Domains\ProductManagement\Application\Contract\CommerceOrderSourcePort;
use Domains\ProductManagement\Application\Contract\ProductCommercialReferencePort;
use Kernel\Module\ActiveModuleResolver;

/** Existing read-only transport/credential boundary. Store identity is independently checked on every call. */
final readonly class ShopifyCommerceOrderSource implements CommerceOrderSourcePort
{
    private const ORDER=<<<'GQL'
query COSCommerceOrder($id: ID!) {
  currentAppInstallation { accessScopes { handle } }
  order(id: $id) {
    id createdAt updatedAt cancelledAt test edited taxesIncluded
    displayFinancialStatus displayFulfillmentStatus
    originalTotalPriceSet { shopMoney { amount currencyCode } }
    totalPriceSet { shopMoney { amount currencyCode } }
    currentTotalPriceSet { shopMoney { amount currencyCode } }
    totalDiscountsSet { shopMoney { amount currencyCode } }
    totalTaxSet { shopMoney { amount currencyCode } }
    totalShippingPriceSet { shopMoney { amount currencyCode } }
    lineItems(first: 100) {
      nodes { id quantity sku product { id } variant { id } originalUnitPriceSet { shopMoney { amount currencyCode } } }
      pageInfo { hasNextPage endCursor }
    }
  }
}
GQL;
    private const JOURNEY=<<<'GQL'
query COSCommerceOrderJourney($id: ID!) {
  currentAppInstallation { accessScopes { handle } }
  order(id: $id) {
    id createdAt
    customerJourneySummary { ready lastVisit { occurredAt utmParameters { source medium campaign } } }
  }
}
GQL;
    private const HISTORY=<<<'GQL'
query COSCommerceOrderHistory($id: ID!) {
  currentAppInstallation { accessScopes { handle } }
  order(id: $id) {
    id createdAt
    customerJourneySummary {
      ready firstVisit { id occurredAt utmParameters { source medium campaign } }
      lastVisit { id occurredAt utmParameters { source medium campaign } }
      momentsCount { count precision }
      moments(first: 100) { nodes { occurredAt ... on CustomerVisit { id utmParameters { source medium campaign } } } pageInfo { hasNextPage hasPreviousPage } }
    }
  }
}
GQL;
    private const LINES=<<<'GQL'
query COSCommerceOrderLines($id: ID!, $after: String!) {
  currentAppInstallation { accessScopes { handle } }
  order(id: $id) {
    id updatedAt
    lineItems(first: 100, after: $after) {
      nodes { id quantity sku product { id } variant { id } originalUnitPriceSet { shopMoney { amount currencyCode } } }
      pageInfo { hasNextPage endCursor }
    }
  }
}
GQL;
    private const PAGE=<<<'GQL'
query COSCommerceOrderPage($after: String, $filter: String) {
  currentAppInstallation { accessScopes { handle } }
  orders(first: 25, after: $after, sortKey: ID, query: $filter) {
    nodes { id }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;
    public function __construct(private ShopifyObservationStore $store,private ShopifyReadClient $read,
        private ShopifyClientCredentials $credentials,private ActiveModuleResolver $modules,private ProductCommercialReferencePort $products){}

    public function order(string $org,string $channel,string $connection,string $externalId):array
    {
        self::gid($externalId,'Order');
        $c=$this->connection($org,$channel,$connection);
        $token=$this->credentials->accessToken($c['shop_domain'],$c['secret_ref'],$org,$connection);
        $data=$this->read->query($c['shop_domain'],$token,self::ORDER,['id'=>$externalId])['data'];
        self::assertOrderScope($data);
        $raw=$data['order']??null;
        if (!is_array($raw)||($raw['id']??null)!==$externalId) throw new DomainException('Shopify order identity unavailable.');
        $created=self::time($raw['createdAt']??null);$updated=self::time($raw['updatedAt']??null);
        if ($updated<$created||!is_bool($raw['test']??null)||!is_bool($raw['edited']??null)||!is_bool($raw['taxesIncluded']??null)||!array_key_exists('cancelledAt',$raw)) throw new DomainException('Invalid Shopify order observation.');
        $currency=$raw['originalTotalPriceSet']['shopMoney']['currencyCode']??null;
        if (!in_array($currency,['USD','EUR','UAH'],true)) throw new DomainException('Unsupported Shopify order currency.');
        $original=self::money($raw['originalTotalPriceSet']??null,$currency);
        $beforeReturns=self::money($raw['totalPriceSet']??null,$currency);
        $current=self::money($raw['currentTotalPriceSet']??null,$currency);
        $financial=self::status($raw['displayFinancialStatus']??null);
        $fulfillment=self::status($raw['displayFulfillmentStatus']??null);
        $cancelled=$raw['cancelledAt']===null?null:self::time($raw['cancelledAt']);
        $items=$this->allLines($c['shop_domain'],$token,$externalId,$updated,$raw['lineItems']??null);
        $raw['lineItems']=$items;
        // Optional attribution scopes must not prevent a valid commercial import.
        // Keep last-visit evidence independent of the larger moments query and order revision.
        unset($raw['customerJourneySummary']);
        $summary=$this->optionalJourney($c['shop_domain'],$token,$externalId,$created,self::JOURNEY);
        $journey=self::journey($summary,$created);
        $historySummary=$this->optionalJourney($c['shop_domain'],$token,$externalId,$created,self::HISTORY);
        $journeyHistory=self::journeyHistory($historySummary,$created,$externalId);
        // A different last visit during the two observations requires a fresh source read.
        if($journeyHistory!==null&&self::journey($historySummary,$created)!==$journey)$journeyHistory=null;
        $reason=null;$terms=null;
        if($raw['test'])$reason='test_order';
        elseif($raw['edited']||$cancelled!==null||$current!==$original||$beforeReturns!==$original||in_array($financial,['REFUNDED','PARTIALLY_REFUNDED','VOIDED'],true))$reason='historical_or_changed_terms';
        else {
            if(!is_array($items)||($items['pageInfo']['hasNextPage']??null)!==false||!is_array($items['nodes']??null)||!array_is_list($items['nodes'])||count($items['nodes'])<1||count($items['nodes'])>1000)
                $reason='incomplete_line_items';
            else {
                $lines=[];$seen=[];
                foreach($items['nodes'] as $line){
                    if(!is_array($line))throw new DomainException('Invalid Shopify line.');
                    $lineId=self::gid($line['id']??null,'LineItem');
                    if(isset($seen[$lineId]))throw new DomainException('Duplicate Shopify line identity.');
                    $seen[$lineId]=true;
                    $productGid=$line['product']['id']??null;$variantGid=$line['variant']['id']??null;
                    if(!is_string($productGid)||!is_string($variantGid)){$reason='unmapped_line';break;}
                    self::gid($productGid,'Product');self::gid($variantGid,'ProductVariant');
                    $p=$this->store->mapping($org,$connection,'product',$productGid)['canonical_id']??null;
                    $v=$this->store->mapping($org,$connection,'variant',$variantGid)['canonical_id']??null;
                    if(!is_string($p)||!is_string($v)){$reason='unmapped_line';break;}
                    $ref=$this->products->quoteReference($org,$p,$v);
                    if($ref===null||($ref['sku']??null)!==($line['sku']??null)){$reason='unmapped_line';break;}
                    // Canonical title avoids importing personalized line descriptions.
                    $lines[$lineId]=['sku'=>$ref['sku'],'title'=>$ref['name'],'product_id'=>$p,'variant_id'=>$v,
                        'quantity'=>$line['quantity']??null,'unit_price'=>self::money($line['originalUnitPriceSet']??null,$currency)];
                }
                if($reason===null){
                    ksort($lines,SORT_STRING);
                    $terms=['currency'=>$currency,'lines'=>array_values($lines),'total'=>$original,
                        'discount'=>self::money($raw['totalDiscountsSet']??null,$currency),'tax_included'=>$raw['taxesIncluded'],
                        'tax'=>self::money($raw['totalTaxSet']??null,$currency),
                        'shipping'=>self::money($raw['totalShippingPriceSet']??null,$currency)];
                }
            }
        }
        $fresh=$this->connection($org,$channel,$connection); // Re-attest connection after provider read.
        if($fresh['shop_domain']!==$c['shop_domain']||$fresh['secret_ref']!==$c['secret_ref'])throw new DomainException('Commerce source changed.');
        // Hash the provider response independently of COS mapping revisions. Persist only the digest and redacted observation.
        $providerHash=hash('sha256',json_encode($raw,JSON_THROW_ON_ERROR));
        return ['provider_hash'=>$providerHash,'channel'=>'shopify','store_reference'=>$connection,'external_order_id'=>$externalId,'created_at'=>$created,'updated_at'=>$updated,
            'financial_status'=>$financial,'fulfillment_status'=>$fulfillment,'cancelled_at'=>$cancelled,'test'=>$raw['test'],'edited'=>$raw['edited'],'tax_included'=>$raw['taxesIncluded'],
            'currency'=>$currency,'original_total'=>$original,'current_total'=>$current,'terms'=>$terms,'blocked_reason'=>$reason,'journey'=>$journey,'journey_history'=>$journeyHistory];
    }

    public function page(string $org,string $channel,string $connection,?string $cursor,?string $cutoff=null):array
    {
        if($cursor!==null&&(trim($cursor)===''||strlen($cursor)>512))throw new DomainException('Invalid order cursor.');
        $c=$this->connection($org,$channel,$connection);
        $token=$this->credentials->accessToken($c['shop_domain'],$c['secret_ref'],$org,$connection);
        $filter=$cutoff===null?null:'created_at:<='.self::time($cutoff);
        $data=$this->read->query($c['shop_domain'],$token,self::PAGE,['after'=>$cursor,'filter'=>$filter])['data'];
        self::assertOrderScope($data);
        $page=$data['orders']??null;
        if(!is_array($page)||!is_array($page['nodes']??null)||!array_is_list($page['nodes'])||count($page['nodes'])>25||!is_bool($page['pageInfo']['hasNextPage']??null))throw new DomainException('Invalid Shopify order page.');
        $ids=[];foreach($page['nodes'] as $node)$ids[]=self::gid($node['id']??null,'Order');
        if(count(array_unique($ids))!==count($ids))throw new DomainException('Duplicate Shopify order page identity.');
        $next=$page['pageInfo']['hasNextPage']?($page['pageInfo']['endCursor']??null):null;
        if($page['pageInfo']['hasNextPage']&&(!is_string($next)||$next===''||strlen($next)>512||$next===$cursor||$ids===[]))throw new DomainException('Unsafe Shopify order page cursor.');
        $fresh=$this->connection($org,$channel,$connection);
        if($fresh['shop_domain']!==$c['shop_domain']||$fresh['secret_ref']!==$c['secret_ref'])throw new DomainException('Commerce source changed.');
        return ['order_ids'=>$ids,'next_cursor'=>$next,'historical_access'=>in_array('read_all_orders',array_column($data['currentAppInstallation']['accessScopes'],'handle'),true)?'all_orders_scope':'provider_limited'];
    }
    public function pendingPage(string $org,string $channel,string $connection,?string $cursor):array
    {
        $this->connection($org,$channel,$connection);
        return $this->store->pendingOrderDeliveryPage($org,$connection,$cursor);
    }
    public function pending(string $org,string $channel,string $connection):array
    {
        $this->connection($org,$channel,$connection);
        return $this->store->pendingOrderDeliveries($org,$connection);
    }
    public function assertDelivery(string $org,string $channel,string $connection,string $delivery,string $externalId):void
    {
        $this->connection($org,$channel,$connection);
        $this->store->assertOrderDelivery($org,$connection,$delivery,$externalId);
    }
    public function acknowledge(string $org,string $channel,string $connection,string $delivery,string $externalId):void
    {
        $this->connection($org,$channel,$connection);
        $this->store->acknowledgeOrderDelivery($org,$connection,$delivery,$externalId);
    }
    private function connection(string $org,string $channel,string $id):array
    {
        if($channel!=='shopify'||$org===''||strlen($org)>40||preg_match('/^[a-f0-9]{32}$/D',$id)!==1||!$this->modules->isEnabled($org,'product_management'))throw new DomainException('Commerce source unavailable.');
        $c=$this->store->connection($org,$id);
        $unique=$this->store->uniqueConnectionForShop($c['shop_domain']);
        if($unique['organization_id']!==$org||$unique['connection_id']!==$id||$unique['secret_ref']!==$c['secret_ref'])throw new DomainException('Ambiguous commerce source.');
        return $c;
    }
    /** Complete immutable provider revision or fail closed; never import a truncated order. */
    private function allLines(string $shop,string $token,string $id,string $revision,mixed $page):array
    {
        $lines=[];$cursors=[];
        for($n=0;$n<10;$n++){
            if(!is_array($page)||!is_array($page['nodes']??null)||!array_is_list($page['nodes'])||count($page['nodes'])>100||!is_bool($page['pageInfo']['hasNextPage']??null))
                throw new DomainException('Invalid Shopify order line page.');
            foreach($page['nodes'] as $node){
                if(!is_array($node))throw new DomainException('Invalid Shopify line.');
                $lineId=self::gid($node['id']??null,'LineItem');
                if(isset($lines[$lineId]))throw new DomainException('Duplicate Shopify line across pages.');
                $lines[$lineId]=$node;
            }
            if(!$page['pageInfo']['hasNextPage']){
                ksort($lines,SORT_STRING);
                return ['nodes'=>array_values($lines),'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]];
            }
            $cursor=$page['pageInfo']['endCursor']??null;
            if(!is_string($cursor)||$cursor===''||strlen($cursor)>512||isset($cursors[$cursor])||$page['nodes']===[])throw new DomainException('Unsafe Shopify line cursor.');
            $cursors[$cursor]=true;
            if($n===9)throw new DomainException('Shopify order line limit exceeds 1000; manual review required.');
            $data=$this->read->query($shop,$token,self::LINES,['id'=>$id,'after'=>$cursor])['data'];
            self::assertOrderScope($data);
            $order=$data['order']??null;
            if(!is_array($order)||($order['id']??null)!==$id||self::time($order['updatedAt']??null)!==$revision)
                throw \Kernel\Execution\ExecutionFailureException::retryable('Shopify order revision changed during pagination.');
            $page=$order['lineItems']??null;
        }
        throw new DomainException('Shopify order line pagination exhausted.');
    }

    /** No raw landing URL, identifiers, contact fields or arbitrary UTM values leave the provider boundary. */
    private function optionalJourney(string $shop,string $token,string $order,string $created,string $query):?array
    {
        try{
            $data=$this->read->query($shop,$token,$query,['id'=>$order])['data'];
            self::assertOrderScope($data);
            $row=$data['order']??null;
            if(!is_array($row)||($row['id']??null)!==$order||self::time($row['createdAt']??null)!==$created)return null;
            return is_array($row['customerJourneySummary']??null)?$row['customerJourneySummary']:null;
        }catch(DomainException|\JsonException){
            // GraphQL protected-data permission or optional provider failure is unavailable evidence,
            // not an invalid canonical placement. No provider error/PII is persisted.
            return null;
        }
    }
    private static function journeyHistory(mixed $summary,string $placed,string $order):?array
    {
        if(!is_array($summary)||($summary['ready']??null)!==true)return null;
        $page=$summary['moments']??null;$count=$summary['momentsCount']??null;
        $nodes=$page['nodes']??null;
        if(!is_array($nodes)||!array_is_list($nodes)||count($nodes)<1||count($nodes)>100
            ||($page['pageInfo']['hasNextPage']??null)!==false||($page['pageInfo']['hasPreviousPage']??null)!==false
            ||($count['precision']??null)!=='EXACT'||($count['count']??null)!==count($nodes))return null;
        $touches=[];$seen=[];$earliest=(new DateTimeImmutable($placed))->modify('-30 days');
        try{
            $pin=static function(mixed $id)use($order):string{
                if(!is_string($id)||!preg_match('#^gid://shopify/CustomerVisit/[A-Za-z0-9_.:-]{1,120}$#D',$id))
                    throw new DomainException('Unsupported visit identity.');
                return hash('sha256',$order.':'.$id);
            };
            foreach($nodes as $v){
                $id=$pin($v['id']??null);if(isset($seen[$id]))return null;$seen[$id]=true;
                $at=self::time($v['occurredAt']??null);
                if($at>$placed||new DateTimeImmutable($at)<$earliest)return null;
                $u=$v['utmParameters']??null;$tags=null;
                if(is_array($u)&&is_string($u['source']??null)&&is_string($u['medium']??null)&&is_string($u['campaign']??null)
                    &&preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$u['source'])
                    &&preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$u['medium'])
                    &&preg_match('/^(?:mkt|mkpl)-[a-f0-9]{24}$/D',$u['campaign']))
                    $tags=['utm_source'=>$u['source'],'utm_medium'=>$u['medium'],'utm_campaign'=>$u['campaign']];
                $touches[]=['touch_id'=>$id,'visit_at'=>$at,'tags'=>$tags];$seen[$id]=$at;
            }
            $first=$pin($summary['firstVisit']['id']??null);$last=$pin($summary['lastVisit']['id']??null);
            if(!isset($seen[$first],$seen[$last])||(count($touches)>1&&$first===$last))return null;
            // Canonical order independent of provider pagination order.
            usort($touches,static fn(array $a,array $b):int=>[$a['visit_at'],$a['touch_id']]<=>[$b['visit_at'],$b['touch_id']]);
            if($seen[$first]!==$touches[0]['visit_at']||$seen[$last]!==$touches[count($touches)-1]['visit_at']
                ||$seen[$first]!==self::time($summary['firstVisit']['occurredAt']??null)
                ||$seen[$last]!==self::time($summary['lastVisit']['occurredAt']??null))return null;
            return ['model'=>'shopify-journey-moments-v1','first_touch'=>$first,'last_touch'=>$last,'touches'=>$touches];
        }catch(DomainException){return null;}
    }

    private static function journey(mixed $summary,string $placed):?array
    {
        if(!is_array($summary)||($summary['ready']??null)!==true)return null;
        $v=$summary['lastVisit']??null;$u=$v['utmParameters']??null;
        if(!is_array($v)||!is_array($u))return null;
        $source=$u['source']??null;$medium=$u['medium']??null;$campaign=$u['campaign']??null;
        foreach([$source,$medium] as $s)if(!is_string($s)||!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$s))return null;
        if(!is_string($campaign)||!preg_match('/^(?:mkt|mkpl)-[a-f0-9]{24}$/D',$campaign))return null;
        try{$at=self::time($v['occurredAt']??null);}catch(DomainException){return null;}
        if($at>$placed)return null;
        return ['model'=>'shopify-last-visit-utm-v1','visit_at'=>$at,'utm_source'=>$source,'utm_medium'=>$medium,'utm_campaign'=>$campaign];
    }

    private static function assertOrderScope(array $data):void
    {
        $scopes=$data['currentAppInstallation']['accessScopes']??null;
        if(!is_array($scopes)||!array_is_list($scopes))throw new DomainException('Shopify order scope not verified.');
        $handles=array_column($scopes,'handle');
        if(!in_array('read_orders',$handles,true)&&!in_array('write_orders',$handles,true))throw new DomainException('Shopify read_orders scope not granted.');
    }
    private static function gid(mixed $id,string $kind):string
    {
        if(!is_string($id)||preg_match('#^gid://shopify/'.$kind.'/[1-9][0-9]{0,18}$#D',$id)!==1)throw new DomainException('Invalid Shopify resource ID.');
        return $id;
    }
    private static function time(mixed $value):string
    {
        if(!is_string($value)||preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D',$value)!==1)throw new DomainException('Invalid Shopify timestamp.');
        $d=new DateTimeImmutable($value);
        if(DateTimeImmutable::getLastErrors()!==false)throw new DomainException('Invalid Shopify timestamp.');
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
    private static function status(mixed $value):string
    {
        if(!is_string($value)||preg_match('/^[A-Z_]{1,40}$/D',$value)!==1)throw new DomainException('Invalid Shopify status.');
        return $value;
    }
    private static function money(mixed $bag,string $currency):string
    {
        $m=is_array($bag)?($bag['shopMoney']??null):null;$amount=$m['amount']??null;
        if(!is_string($amount)||($m['currencyCode']??null)!==$currency||preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D',$amount,$parts)!==1)throw new DomainException('Unsupported Shopify money representation.');
        return $parts[1].'.'.str_pad($parts[2]??'',2,'0');
    }
}
