<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Application\Service;

use DomainException;
use Domains\ProductManagement\Application\Contract\ProductManagementStoreInterface;
use Domains\ProductManagement\Application\Contract\ProductMediaFileReferenceInterface;

/** Owns Product/Variant media links; Platform Storage still owns file bytes. */
final readonly class ProductMediaService
{
    public function __construct(
        private ProductManagementStoreInterface $products,
        private ProductMediaFileReferenceInterface $files,
    ) {}

    public function attach(string $org,string $productId,string $fileId,?string $variantId,
        string $role,int $sortOrder,string $key,string $actor,string $correlation):array
    {
        if($org===''||strlen($org)>64||$key===''||strlen($key)>191
            ||$actor===''||strlen($actor)>190||$correlation===''||strlen($correlation)>191
            ||!preg_match('/^[a-f0-9]{32}$/D',$productId)
            ||($variantId!==null&&!preg_match('/^[a-f0-9]{32}$/D',$variantId))
            ||!in_array($role,['cover','gallery'],true)
            ||$sortOrder<0||$sortOrder>100000) {
            throw new DomainException('Invalid Product Media attachment context.');
        }
        $file=$this->files->verifiedImage($org,$fileId);
        return $this->products->attachMedia($org,$productId,$variantId,$file,$role,$sortOrder,
            $key,$actor,$correlation);
    }

    public function detach(string $org,string $mediaId,string $key,string $actor,string $correlation):array
    {
        if($org===''||strlen($org)>64||!preg_match('/^[a-f0-9]{32}$/D',$mediaId)
            ||$key===''||strlen($key)>191||$actor===''||strlen($actor)>190
            ||$correlation===''||strlen($correlation)>191) {
            throw new DomainException('Invalid Product Media detachment context.');
        }
        return $this->products->detachMedia($org,$mediaId,$key,$actor,$correlation);
    }

    public function search(string $org,string $productId,int $limit=30,int $offset=0):array
    {
        if($org===''||strlen($org)>64||!preg_match('/^[a-f0-9]{32}$/D',$productId)
            ||$limit<1||$limit>100||$offset<0) {
            throw new DomainException('Invalid Product Media query bounds.');
        }
        return $this->products->searchMedia($org,$productId,$limit,$offset);
    }
}
