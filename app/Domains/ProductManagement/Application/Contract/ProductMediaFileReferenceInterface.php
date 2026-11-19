<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Application\Contract;

/** Read-only verified reference. Does not copy/upload/re-authorize a file. */
interface ProductMediaFileReferenceInterface
{
    /** @return array{file_id:string,storage_key:string,sha256:string,mime_type:string} */
    public function verifiedImage(string $organizationId,string $fileId):array;
}
