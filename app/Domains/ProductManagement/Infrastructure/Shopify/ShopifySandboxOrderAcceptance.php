<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/** Read-only acceptance of the real commerce intake boundary, not a Sale,
 * payment, stock commitment or production inventory ownership attestation. */
final class ShopifySandboxOrderAcceptance
{
    public static function verify(array $snapshot,string $connection,string $order):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$connection)||
            !preg_match('#^gid://shopify/Order/[1-9][0-9]{0,18}$#D',$order)||
            ($snapshot['channel']??null)!=='shopify'||($snapshot['store_reference']??null)!==$connection||
            ($snapshot['external_order_id']??null)!==$order||($snapshot['test']??null)!==true||
            ($snapshot['blocked_reason']??null)!=='test_order'||!array_key_exists('terms',$snapshot)||$snapshot['terms']!==null||
            !is_string($snapshot['provider_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$snapshot['provider_hash']))
            throw new DomainException('Verified sandbox test order blocked from commercial intake required.');
        return ['status'=>'SANDBOX_READBACK_VERIFIED','connection_id'=>$connection,'order_gid'=>$order,
            'provider_hash'=>$snapshot['provider_hash'],'test_order'=>true,'commercial_intake'=>'blocked_test_order',
            'inventory_authority'=>'external_review','native_order_created'=>false,'local_stock_mutation'=>false,
            'payment_settled'=>false,'provider_mutation'=>false];
    }

    /**
     * Compare independent commercial and fulfillment reads of the same test
     * order revision. This verifies provider observation only, not dispatch,
     * physical restock, Finance settlement or production release.
     */
    public static function verifyLifecycle(array $snapshot,array $evidence,string $org,string $connection,string $order):array
    {
        $base=self::verify($snapshot,$connection,$order);
        if($org===''||($evidence['organization_id']??null)!==$org||($evidence['connection_id']??null)!==$connection
            ||($evidence['channel']??null)!=='shopify'||($evidence['external_order_id']??null)!==$order
            ||($evidence['test']??null)!==true||($evidence['inventory_authority']??null)!=='external_review'
            ||($evidence['local_stock_write_allowed']??null)!==false||($evidence['physical_commitment_verified']??null)!==false
            ||!is_string($evidence['provider_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$evidence['provider_hash'])
            ||!is_string($snapshot['updated_at']??null)||($evidence['provider_updated_at']??null)!==$snapshot['updated_at']
            ||!array_key_exists('cancelled_at',$snapshot)||!array_key_exists('cancelled_at',$evidence)
            ||$evidence['cancelled_at']!==$snapshot['cancelled_at']
            ||!is_string($snapshot['fulfillment_status']??null)||($evidence['provider_fulfillment_status']??null)!==$snapshot['fulfillment_status']
            ||!is_array($snapshot['line_identities']??null)||!is_array($evidence['lifecycle']['lines']??null)
            ||!is_array($evidence['lifecycle']['review_reasons']??null))
            throw new DomainException('Independent sandbox lifecycle observation differs from the test Order.');
        $identities=[];$totals=['ordered'=>0,'assigned_pending'=>0,'provider_fulfilled'=>0,'return_requested'=>0,'return_processed'=>0];
        $cancelled=0;$cancelledKnown=$snapshot['cancelled_at']!==null;
        foreach($evidence['lifecycle']['lines'] as $id=>$line){
            if(!is_string($id)||!preg_match('#^gid://shopify/LineItem/[1-9][0-9]{0,18}$#D',$id)||!is_array($line))
                throw new DomainException('Invalid sandbox lifecycle line identity.');
            foreach($totals as $field=>&$total){
                $qty=$line[$field]??null;
                if(!is_int($qty)||$qty<0||$qty>1000000)throw new DomainException('Invalid sandbox lifecycle quantity.');
                $total+=$qty;
            }
            unset($total);
            $identities[$id]=$line['ordered'];
            if($line['ordered']<1||$line['provider_fulfilled']+$line['assigned_pending']>$line['ordered']
                ||$line['return_processed']>$line['return_requested']||$line['return_requested']>$line['provider_fulfilled'])
                throw new DomainException('Sandbox lifecycle quantities disagree.');
            $qty=$line['cancelled_unfulfilled']??null;
            if($qty===null)$cancelledKnown=false;
            elseif(!is_int($qty)||$qty<0||$qty!==$line['ordered']-$line['provider_fulfilled']||$snapshot['cancelled_at']===null||$line['assigned_pending']!==0)
                throw new DomainException('Invalid sandbox cancellation quantity.');
            else $cancelled+=$qty;
        }
        ksort($identities,SORT_STRING);$expected=$snapshot['line_identities'];ksort($expected,SORT_STRING);
        if($identities===[]||$identities!==$expected)throw new DomainException('Sandbox lifecycle line identities differ.');
        $review=$evidence['lifecycle']['review_reasons'];
        if(!array_is_list($review))throw new DomainException('Invalid sandbox review reasons.');
        foreach($review as $reason)if(!is_string($reason)||!preg_match('/^[a-z_]{1,80}$/D',$reason))
            throw new DomainException('Invalid sandbox review reason.');
        return [...$base,'status'=>'SANDBOX_LIFECYCLE_READBACK_VERIFIED',
            'organization_id'=>$org,'fulfillment_provider_hash'=>$evidence['provider_hash'],
            'provider_updated_at'=>$snapshot['updated_at'],'provider_fulfillment_status'=>$snapshot['fulfillment_status'],
            'provider_quantities'=>[...$totals,'cancelled_unfulfilled'=>$cancelledKnown?$cancelled:null],
            'provider_review_reasons'=>$review,'physical_commitment_verified'=>false,'ownership_changed'=>false,
            'scenario_acceptance'=>'independent_review_required'];
    }
}
