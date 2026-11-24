<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

/** Resolve requested scope from live membership, not caller-asserted authority. */
interface SalesWorkspaceScopeInterface
{
    public function resolve(string $org,int $actor,string $scope,?int $owner):?int;
}
