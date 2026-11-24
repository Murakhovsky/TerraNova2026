<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

final class ShopifyWebhookVerifier
{
    public static function verify(string $rawBody,string $hmacHeader,string $appSecret): void
    {
        if ($appSecret===''||strlen($rawBody)>1048576||$rawBody==='') throw new DomainException('Shopify webhook not acceptable.');
        $provided=base64_decode($hmacHeader,true);
        if ($provided===false||strlen($provided)!==32) throw new DomainException('Invalid Shopify webhook signature.');
        $computed=hash_hmac('sha256',$rawBody,$appSecret,true);
        if (!hash_equals($computed,$provided)) throw new DomainException('Shopify webhook authentication failed.');
    }
}
