<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

interface HermesAnalystTransport
{
    /** @return array<mixed> */
    public function get(string $url,string $token):array;
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function post(string $url,string $token,string $key,array $payload):array;
}
