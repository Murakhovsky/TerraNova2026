<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;
interface MetaReadTransportInterface {
 /** @return array<string,mixed> */
 public function get(string $url,string $accessToken):array;
}
