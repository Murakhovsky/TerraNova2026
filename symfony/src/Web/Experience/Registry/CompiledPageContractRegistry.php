<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

use InvalidArgumentException;

final class CompiledPageContractRegistry implements PageContractRegistryInterface
{
    /** @var array<string,PageContract>|null */
    private ?array $contracts = null;

    public function __construct(
        private readonly PageContractLoader $loader,
        private readonly PageContractValidator $validator,
    ) {
    }

    public function all(): array
    {
        if ($this->contracts !== null) {
            return $this->contracts;
        }

        $contracts = [];
        $routes = [];

        foreach ($this->loader->load() as $contract) {
            $this->validator->validate($contract);

            if (isset($contracts[$contract->id->value])) {
                throw new InvalidArgumentException('Duplicate page contract id: ' . $contract->id->value);
            }

            if (isset($routes[$contract->routeName])) {
                throw new InvalidArgumentException('Duplicate page route contract: ' . $contract->routeName);
            }

            $contracts[$contract->id->value] = $contract;
            $routes[$contract->routeName] = true;
        }

        ksort($contracts);

        return $this->contracts = $contracts;
    }

    public function get(PageContractId|string $id): PageContract
    {
        $value = $id instanceof PageContractId ? $id->value : $id;
        $contract = $this->all()[$value] ?? null;

        if ($contract === null) {
            throw new InvalidArgumentException('Unknown page contract: ' . $value);
        }

        return $contract;
    }

    public function findByRouteName(string $routeName): ?PageContract
    {
        foreach ($this->all() as $contract) {
            if ($contract->routeName === $routeName) {
                return $contract;
            }
        }

        return null;
    }
}
