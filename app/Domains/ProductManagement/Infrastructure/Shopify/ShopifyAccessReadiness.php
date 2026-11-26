<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

final readonly class ShopifyAccessReadiness
{
    public function __construct(private ShopifyReadClient $reader) {}
    public function inspect(string $shop,string $token):array
    {
        $response=$this->reader->query($shop,$token,'query COSAccess { shop { myshopifyDomain currencyCode } currentAppInstallation { accessScopes { handle } } }');
        $data=$response['data'];
        $currency=$data['shop']['currencyCode']??null;
        $rows=$data['currentAppInstallation']['accessScopes']??null;
        if(($data['shop']['myshopifyDomain']??null)!==$shop||!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)||!is_array($rows))
            throw new DomainException('Shop identity, currency or granted scopes not verified.');
        $scopes=[];
        foreach($rows as $row){
            if(!is_string($row['handle']??null))throw new DomainException('Malformed Shopify scope.');
            $scopes[]=$row['handle'];
        }
        $scopes=array_values(array_unique($scopes));sort($scopes,SORT_STRING);
        $has=static fn(string $scope):bool=>in_array($scope,$scopes,true)||(str_starts_with($scope,'read_')&&in_array('write_'.substr($scope,5),$scopes,true));
        return ['shop_domain'=>$shop,'currency'=>$currency,'scopes'=>$scopes,
            'read_products'=>$has('read_products'),'read_inventory'=>$has('read_inventory'),
            'read_locations'=>$has('read_locations'),'write_products'=>$has('write_products'),
            'write_files'=>$has('write_files'),'write_publications'=>$has('write_publications'),
            'read_orders'=>$has('read_orders')];
    }
    /** Authenticated sample read, not merely a declared OAuth scope. */
    public function verifyCatalogRead(string $shop,string $token):array
    {
        $access=$this->inspect($shop,$token);
        if(!$access['read_products'])throw new DomainException('Product read scope missing.');
        $data=$this->reader->query($shop,$token,'query COSAccessReadback { products(first:1) { nodes { id } pageInfo { hasNextPage } } }')['data'];
        $page=$data['products']??null;
        if(!is_array($page)||!is_array($page['nodes']??null)||count($page['nodes'])>1
            ||!is_bool($page['pageInfo']['hasNextPage']??null))throw new DomainException('Catalog readback unavailable.');
        foreach($page['nodes'] as $row){
            if(!is_string($row['id']??null)||!preg_match('#^gid://shopify/Product/[0-9]+$#D',$row['id']))
                throw new DomainException('Invalid catalog readback identity.');
        }
        return $access+['catalog_read_verified'=>true,'sample_count'=>count($page['nodes'])];
    }
}
