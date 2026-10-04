<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

interface PageContractRegistryInterface
{
    /** @return array<string,PageContract> */
    public function all(): array;

    public function get(PageContractId|string $id): PageContract;

    public function findByRouteName(string $routeName): ?PageContract;
}
