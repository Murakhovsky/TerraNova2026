<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

/** One bounded request only. Never automatically retry a POST. */
interface MetaWriteTransport
{
    public function request(string $method,string $path,array $fields,string $token):array;
}
