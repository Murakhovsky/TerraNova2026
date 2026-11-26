<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use DateTimeImmutable;
use DateTimeZone;

/** Provider assignment is not a physical stock commitment, dispatch, return or local reservation. */
final class ShopifyFulfillmentEvidenceNormalizer
{
    public static function normalize(array $data,string $externalId,bool $includeLifecycle=false):array
    {
        $o=$data['order']??null;
        if(!is_array($o)||($o['id']??null)!==$externalId||!is_bool($o['test']??null)||!array_key_exists('cancelledAt',$o))
            throw new DomainException('Unverified fulfillment order identity.');
        self::gid($externalId,'Order');
        $updated=self::time($o['updatedAt']??null);
        $cancelled=$o['cancelledAt']===null?null:self::time($o['cancelledAt']);
        $status=self::status($o['displayFulfillmentStatus']??null);
        $groups=[];$lines=[];
        foreach(self::nodes($o['fulfillmentOrders']??null,10) as $group){
            $id=self::gid($group['id']??null,'FulfillmentOrder');
            if(isset($groups[$id]))throw new DomainException('Duplicate fulfillment order.');
            $location=self::gid($group['assignedLocation']['location']['id']??null,'Location');
            $items=[];
            foreach(self::nodes($group['lineItems']??null,20) as $item){
                $lineId=self::gid($item['id']??null,'FulfillmentOrderLineItem');
                if(isset($lines[$lineId]))throw new DomainException('Duplicate fulfillment line identity.');
                $lines[$lineId]=true;
                $total=$item['totalQuantity']??null;$remaining=$item['remainingQuantity']??null;
                if(!is_int($total)||$total<0||$total>1000000||!is_int($remaining)||$remaining<0||$remaining>$total)
                    throw new DomainException('Invalid fulfillment assignment quantity.');
                $items[$lineId]=['fulfillment_line_gid'=>$lineId,'order_line_gid'=>self::gid($item['lineItem']['id']??null,'LineItem'),
                    'total_quantity'=>$total,'remaining_quantity'=>$remaining];
            }
            ksort($items,SORT_STRING);
            $groups[$id]=['fulfillment_order_gid'=>$id,'location_gid'=>$location,'status'=>self::status($group['status']??null),
                'request_status'=>self::status($group['requestStatus']??null),'items'=>array_values($items)];
        }
        ksort($groups,SORT_STRING);
        $result=['external_order_id'=>$externalId,'provider_updated_at'=>$updated,'cancelled_at'=>$cancelled,'test'=>$o['test'],
            'provider_fulfillment_status'=>$status,'assignments'=>array_values($groups)];
        if($includeLifecycle)$result['lifecycle']=self::lifecycle($o,$groups);
        return $result+['provider_hash'=>hash('sha256',json_encode($result,JSON_THROW_ON_ERROR))];
    }
    /**
     * Provider-reported quantities only. Successful fulfillment is not carrier
     * delivery; processed return is not proof of physical receipt/restocking.
     * No arithmetic on assignment total-minus-remaining can prove shipment.
     */
    private static function lifecycle(array $order,array $groups):array
    {
        if(!is_bool($order['edited']??null))throw new DomainException('Order edit visibility required.');
        $lines=[];
        foreach(self::nodes($order['lineItems']??null,100) as $line){
            $id=self::gid($line['id']??null,'LineItem');
            if(isset($lines[$id]))throw new DomainException('Duplicate order line.');
            $lines[$id]=['provider_product_gid'=>isset($line['product']['id'])?self::gid($line['product']['id'],'Product'):null,
                'provider_variant_gid'=>isset($line['variant']['id'])?self::gid($line['variant']['id'],'ProductVariant'):null,
                'ordered'=>self::quantity($line['quantity']??null,1),'assigned_pending'=>0,
                'provider_fulfilled'=>0,'return_requested'=>0,'return_processed'=>0,'cancelled_unfulfilled'=>null];
        }
        if($lines===[])throw new DomainException('Missing provider order lines.');
        $review=[];$fulfillmentLines=[];$fulfillments=[];
        foreach($groups as $group){
            foreach($group['items'] as $item){
                $id=$item['order_line_gid'];
                if(!isset($lines[$id]))throw new DomainException('Assignment references unknown order line.');
                if(in_array($group['status'],['OPEN','IN_PROGRESS','ON_HOLD','SCHEDULED'],true))
                    $lines[$id]['assigned_pending']+=$item['remaining_quantity'];
                elseif(!in_array($group['status'],['CLOSED','CANCELLED','INCOMPLETE'],true)||$item['remaining_quantity']>0)
                    $review['assignment_state_review']=true;
            }
        }
        $raw=$order['fulfillments']??null;$count=$order['fulfillmentsCount']??null;
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>10||($count['precision']??null)!=='EXACT'||($count['count']??null)!==count($raw))
            throw new DomainException('Incomplete fulfillment records.');
        foreach($raw as $fulfillment){
            $id=self::gid($fulfillment['id']??null,'Fulfillment');
            if(isset($fulfillments[$id]))throw new DomainException('Duplicate fulfillment.');
            $status=self::status($fulfillment['status']??null);
            $location=isset($fulfillment['location']['id'])?self::gid($fulfillment['location']['id'],'Location'):null;
            $items=[];
            foreach(self::nodes($fulfillment['fulfillmentLineItems']??null,20) as $item){
                $line=self::gid($item['id']??null,'FulfillmentLineItem');
                $orderLine=self::gid($item['lineItem']['id']??null,'LineItem');
                $qty=self::quantity($item['quantity']??null,1);
                if(isset($fulfillmentLines[$line])||!isset($lines[$orderLine]))throw new DomainException('Invalid fulfillment line relationship.');
                $fulfillmentLines[$line]=['order_line_gid'=>$orderLine,'quantity'=>$qty,'status'=>$status,'requested'=>0,'processed'=>0];
                $items[$line]=['fulfillment_line_gid'=>$line,'order_line_gid'=>$orderLine,'quantity'=>$qty];
                if($status==='SUCCESS')$lines[$orderLine]['provider_fulfilled']+=$qty;
                elseif(!in_array($status,['CANCELLED','ERROR','FAILURE'],true))$review['fulfillment_state_review']=true;
            }
            if($status==='SUCCESS'&&$location===null)$review['fulfillment_location_unknown']=true;
            ksort($items,SORT_STRING);
            $fulfillments[$id]=['fulfillment_gid'=>$id,'status'=>$status,'location_gid'=>$location,'items'=>array_values($items)];
        }
        $returns=[];$seen=[];
        foreach(self::nodes($order['returns']??null,10) as $return){
            $id=self::gid($return['id']??null,'Return');
            if(isset($returns[$id]))throw new DomainException('Duplicate return.');
            $status=self::status($return['status']??null);$items=[];
            foreach(self::nodes($return['returnLineItems']??null,20) as $item){
                if(($item['__typename']??null)!=='ReturnLineItem')throw new DomainException('Unsupported return line type.');
                $rid=self::gid($item['id']??null,'ReturnLineItem');
                $fid=self::gid($item['fulfillmentLineItem']['id']??null,'FulfillmentLineItem');
                $qty=self::quantity($item['quantity']??null,1);$processed=self::quantity($item['processedQuantity']??null,0);
                if(isset($seen[$rid])||!isset($fulfillmentLines[$fid])||$processed>$qty)
                    throw new DomainException('Invalid return line relationship.');
                $seen[$rid]=true;
                $entry=&$fulfillmentLines[$fid];$line=$entry['order_line_gid'];
                if(in_array($status,['CANCELED','CANCELLED','DECLINED'],true)){
                    if($processed!==0)throw new DomainException('Cancelled return has processed quantity.');
                }else{
                    if($entry['status']!=='SUCCESS')throw new DomainException('Return references non-successful fulfillment.');
                    $entry['requested']+=$qty;$entry['processed']+=$processed;
                    if($entry['requested']>$entry['quantity']||$entry['processed']>$entry['quantity'])throw new DomainException('Return exceeds fulfillment.');
                    $lines[$line]['return_requested']+=$qty;$lines[$line]['return_processed']+=$processed;
                    if(!in_array($status,['REQUESTED','OPEN','CLOSED'],true))$review['return_state_review']=true;
                }
                unset($entry);
                $items[$rid]=['return_line_gid'=>$rid,'fulfillment_line_gid'=>$fid,'quantity'=>$qty,'processed_quantity'=>$processed];
            }
            ksort($items,SORT_STRING);$returns[$id]=['return_gid'=>$id,'status'=>$status,'items'=>array_values($items)];
        }
        if($order['edited'])$review['order_edited']=true;
        foreach($lines as &$line){
            if($line['provider_fulfilled']>$line['ordered']||$line['return_processed']>$line['return_requested']
                ||$line['return_requested']>$line['provider_fulfilled']||$line['assigned_pending']+$line['provider_fulfilled']>$line['ordered'])
                throw new DomainException('Provider line quantities disagree.');
            if($order['cancelledAt']!==null){
                if($line['assigned_pending']!==0)$review['cancelled_order_open_assignment']=true;
                if(!$order['edited']&&$line['assigned_pending']===0&&!isset($review['fulfillment_state_review'])&&!isset($review['assignment_state_review']))
                    $line['cancelled_unfulfilled']=$line['ordered']-$line['provider_fulfilled'];
            }
        }
        unset($line);
        ksort($lines,SORT_STRING);ksort($fulfillments,SORT_STRING);ksort($returns,SORT_STRING);ksort($review,SORT_STRING);
        return ['lines'=>$lines,'fulfillments'=>array_values($fulfillments),'returns'=>array_values($returns),'review_reasons'=>array_keys($review)];
    }
    private static function quantity(mixed $v,int $min):int
    {
        if(!is_int($v)||$v<$min||$v>100000)throw new DomainException('Invalid provider lifecycle quantity.');
        return $v;
    }
    private static function nodes(mixed $connection,int $limit):array
    {
        if(!is_array($connection)||($connection['pageInfo']['hasNextPage']??null)!==false||
            !is_array($connection['nodes']??null)||!array_is_list($connection['nodes'])||count($connection['nodes'])>$limit)
            throw new DomainException('Incomplete bounded fulfillment evidence; review required.');
        foreach($connection['nodes'] as $node)if(!is_array($node))throw new DomainException('Invalid fulfillment node.');
        return $connection['nodes'];
    }
    private static function gid(mixed $id,string $kind):string
    {
        if(!is_string($id)||!preg_match('#^gid://shopify/'.$kind.'/[1-9][0-9]{0,18}$#D',$id))
            throw new DomainException('Invalid fulfillment resource identity.');
        return $id;
    }
    private static function status(mixed $v):string
    {
        if(!is_string($v)||!preg_match('/^[A-Z_]{1,40}$/D',$v))throw new DomainException('Invalid provider status.');
        return $v;
    }
    private static function time(mixed $v):string
    {
        if(!is_string($v)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D',$v))
            throw new DomainException('Invalid fulfillment observation timestamp.');
        $d=new DateTimeImmutable($v);
        if(DateTimeImmutable::getLastErrors()!==false)throw new DomainException('Invalid fulfillment observation timestamp.');
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
