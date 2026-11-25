<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DomainException;

/** Project one captured placement using its contiguous Sales lifecycle journal, never its payment status. */
final class MarketingCommerceLifecyclePolicy
{
    /** Null means source unavailable; invalid/incomplete journals must not be counted. */
    public static function project(string $org,string $order,callable $read):?array
    {
        $cursor=1;$head=null;$status='placed';$seen=[];$returned=0;
        for($i=0;$i<10;$i++){
            $page=$read($cursor,100);
            if($page===null&&$cursor===1)return null;
            if(!is_array($page)||!is_int($page['order_revision']??null)||$page['order_revision']<$cursor||
                !is_array($page['events']??null)||!array_is_list($page['events'])||count($page['events'])>100||
                !is_bool($page['has_more']??null)||!is_int($page['next_after_revision']??null))
                throw new DomainException('Invalid lifecycle source page.');
            $head??=$page['order_revision'];
            if($head!==$page['order_revision'])throw new DomainException('Lifecycle changed during read.');
            foreach($page['events'] as $e){
                $id=$e['event_id']??null;$type=$e['event_type']??null;$next=$e['status']??null;$qty=$e['effect_quantity']??null;
                if(($e['organization_id']??null)!==$org||($e['order_id']??null)!==$order||($e['schema_version']??null)!=='1.0.0'||
                    ($e['revision']??null)!==$cursor+1||!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||isset($seen[$id])||
                    ($e['previous_status']??null)!==$status||!is_int($qty)||$qty<0||$qty>100000000)
                    throw new DomainException('Lifecycle evidence identity or sequence invalid.');
                MarketingCommerceAttributionPolicy::time($e['occurred_at']??null);
                $valid=match($type){
                    'sales.commerce.order.confirm'=>$status==='placed'&&$next==='confirmed'&&$qty===0,
                    'sales.commerce.order.allocate','sales.commerce.order.release'=>$status==='confirmed'&&$next===$status&&$qty>0,
                    'sales.commerce.order.fulfill'=>$status==='confirmed'&&in_array($next,['confirmed','completed'],true)&&$qty>0,
                    'sales.commerce.order.cancel'=>in_array($status,['placed','confirmed'],true)&&$next==='cancelled',
                    'sales.commerce.order.return'=>in_array($status,['confirmed','completed','cancelled'],true)&&$next===$status&&$qty>0,
                    default=>false,
                };
                if(!$valid)throw new DomainException('Lifecycle state transition invalid.');
                if($type==='sales.commerce.order.return')$returned+=$qty;
                $seen[$id]=true;$status=$next;$cursor++;
            }
            if($cursor!==$page['next_after_revision']||$cursor>$head||$page['has_more']!==($cursor<$head)||
                ($page['has_more']&&$page['events']===[]))throw new DomainException('Lifecycle source cursor invalid.');
            if(!$page['has_more'])return ['status'=>$status,'returned_quantity'=>$returned,'revision'=>$cursor];
        }
        throw new DomainException('Lifecycle source exceeds bounded history.');
    }
}
