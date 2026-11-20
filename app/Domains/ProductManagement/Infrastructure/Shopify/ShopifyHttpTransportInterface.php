<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

interface ShopifyHttpTransportInterface
{
    /** @param array<string,string> $headers @return array{status:int,body:string} */
    public function post(string $url, array $headers, string $body): array;
}
