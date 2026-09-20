<?php
declare(strict_types=1);

namespace App\Application\System\Contract;

interface DependencyHealthCheckInterface
{
    /** @return array{status:string,canonical_mysql:string,legacy_mysql:string} */
    public function check(): array;
}
