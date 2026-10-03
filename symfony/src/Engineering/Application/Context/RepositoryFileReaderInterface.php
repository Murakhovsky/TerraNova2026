<?php
declare(strict_types=1);

namespace App\Engineering\Application\Context;

interface RepositoryFileReaderInterface
{
    /**
     * @param list<string> $paths
     * @return list<array{path:string,content:string,complete:bool,size:int,sha256:string}>
     */
    public function readMany(array $paths): array;
}
