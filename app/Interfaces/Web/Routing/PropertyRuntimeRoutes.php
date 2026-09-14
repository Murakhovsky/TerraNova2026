<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class PropertyRuntimeRoutes
{
    public static function register(RouterInterface $router): void
    {
        $runtime=static fn(string $action):array=>['namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'property_runtime','action'=>$action];
        $canonical=static fn(string $action):array=>['namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'property_canonical','action'=>$action];
        $id='[-A-Za-z0-9._:]{3,80}';

        $router->addGet('/api/v1/property-registry',$runtime('index'));
        $router->addGet('/api/v1/property-registry/health',$runtime('health'));
        $router->addGet('/api/v1/property-registry/assets/{assetId:'.$id.'}',$canonical('asset'));
        $router->addPost('/api/v1/property-registry/assets',$canonical('createAsset'));
        $router->addPost('/api/v1/property-registry/assets/{assetId:'.$id.'}',$canonical('updateAsset'));
        $router->addPost('/api/v1/property-registry/assets/{assetId:'.$id.'}/lifecycle',$canonical('lifecycle'));
        $router->addPost('/api/v1/property-registry/assets/{assetId:'.$id.'}/inventory',$canonical('createInventory'));
        $router->addPost('/api/v1/property-registry/inventory/{inventoryId:'.$id.'}/price',$canonical('inventoryPrice'));
        $router->addPost('/api/v1/property-registry/inventory/{inventoryId:'.$id.'}/status',$canonical('inventoryStatus'));
        $router->addPost('/api/v1/property-registry/inventory/{inventoryId:'.$id.'}/reservations',$canonical('reserve'));
        $router->addPost('/api/v1/property-registry/inventory/{inventoryId:'.$id.'}/reservations/release',$canonical('releaseReservation'));
        $router->addPost('/api/v1/property-registry/inventory/{inventoryId:'.$id.'}/listings',$canonical('createListing'));
        $router->addPost('/api/v1/property-registry/listings/{listingId:'.$id.'}',$canonical('updateListing'));
        $router->addPost('/api/v1/property-registry/listings/{listingId:'.$id.'}/publish',$canonical('publishListing'));
        $router->addPost('/api/v1/property-registry/listings/{listingId:'.$id.'}/hide',$canonical('hideListing'));
    }
}
