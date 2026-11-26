<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

interface ShopifyStagedImageUploadPort
{
    public function upload(string $url,array $parameters,string $bytes,string $mime,string $filename):void;
}
