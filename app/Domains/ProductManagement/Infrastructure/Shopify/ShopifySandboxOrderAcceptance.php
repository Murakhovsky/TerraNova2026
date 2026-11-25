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
}
