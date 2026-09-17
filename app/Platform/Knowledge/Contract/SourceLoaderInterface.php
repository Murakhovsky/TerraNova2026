<?php
declare(strict_types=1);

namespace Platform\Knowledge\Contract;

use Platform\Knowledge\Model\Source;

interface SourceLoaderInterface
{
    /** @return iterable<array{external_id:string,title:string,mime_type:string,content:string,metadata?:array<string,mixed>}> */
    public function load(Source $source): iterable;
}
