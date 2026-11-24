<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/**
 * Extract only a stable external Order pointer from HMAC-verified Shopify
 * deliveries. Never keep raw order JSON, addresses, customer data or totals
 * in the Product Management webhook inbox. Sales must independently fetch
 * and reconcile the order through its owned application boundary.
 */
final class ShopifyOrderWebhookEvidence
{
    private const TOPICS=[
        'orders/create'=>true,
        'orders/updated'=>true,
        'orders/paid'=>true,
        'orders/cancelled'=>true,
        'orders/fulfilled'=>true,
    ];

    public static function extractVerified(
        string $rawBody,string $signature,string $appSecret,string $topic
    ):?string {
        // Authenticate raw bytes even when caller previously checked HMAC.
        ShopifyWebhookVerifier::verify($rawBody,$signature,$appSecret);
        if(!isset(self::TOPICS[$topic])) return null;
        $payload=json_decode($rawBody,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($payload)||array_is_list($payload)) {
            throw new DomainException('Shopify order event must contain a JSON object.');
        }
        $numeric=$payload['id']??null;
        $gid=$payload['admin_graphql_api_id']??null;
        if(is_int($numeric)&&$numeric>0) $expected='gid://shopify/Order/'.$numeric;
        elseif(is_string($numeric)&&preg_match('/^[1-9][0-9]{0,18}$/D',$numeric)) {
            $expected='gid://shopify/Order/'.$numeric;
        } else {
            throw new DomainException('Shopify order event missing numeric Order ID.');
        }
        if($gid!==null && (!is_string($gid)||!hash_equals($expected,$gid))) {
            throw new DomainException('Shopify order event inconsistent GraphQL identity.');
        }
        return $expected;
    }
}
