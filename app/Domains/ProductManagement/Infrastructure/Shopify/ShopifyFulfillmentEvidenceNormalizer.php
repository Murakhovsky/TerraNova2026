<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use DateTimeImmutable;
use DateTimeZone;

/** Provider assignment is not a physical stock commitment, dispatch, return or local reservation. */
final class ShopifyFulfillmentEvidenceNormalizer
{
    public static function normalize(array $data,string $externalId):array
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
        return $result+['provider_hash'=>hash('sha256',json_encode($result,JSON_THROW_ON_ERROR))];
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
