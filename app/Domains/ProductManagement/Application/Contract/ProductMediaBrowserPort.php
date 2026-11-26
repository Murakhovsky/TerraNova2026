<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Application\Contract;

/** Tenant-scoped, read-only Documents image projection. Never returns storage keys. */
interface ProductMediaBrowserPort
{
    public function candidates(string $organizationId,string $query,int $limit,int $offset):array;
    /** @return array{mime_type:string,bytes:string} */
    public function preview(string $organizationId,string $fileId):array;
}
