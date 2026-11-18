<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use RuntimeException;

final readonly class CurlShopifyHttpTransport implements ShopifyHttpTransportInterface
{
    public function post(string $url, array $headers, string $body): array
    {
        if (!preg_match('#^https://[a-z0-9][a-z0-9-]*\.myshopify\.com/admin/(?:api/2026-10/graphql\.json|oauth/access_token)$#D',$url)) {
            throw new RuntimeException('Shopify outbound host/endpoint rejected.');
        }
        if (!function_exists('curl_init')) throw new RuntimeException('curl extension required.');
        $handle=curl_init($url);
        if ($handle===false) throw new RuntimeException('Cannot initialize HTTPS request.');
        try {
            $lines=[];
            foreach ($headers as $name=>$value) $lines[]=$name.': '.$value;
            curl_setopt_array($handle,[
                CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$lines,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            ]);
            $response=curl_exec($handle);
            if ($response===false) throw new RuntimeException('Shopify HTTPS request failed.');
            $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if (strlen($response)>8388608) throw new RuntimeException('Shopify response exceeds safety limit.');
            return ['status'=>$status,'body'=>$response];
        } finally { curl_close($handle); }
    }
}
