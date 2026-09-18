<?php
declare(strict_types=1);

namespace App\Infrastructure\Module;

use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

final readonly class ModuleCatalogFactory
{
    public function __construct(private string $domainsPath)
    {
    }

    public function create(): ModuleCatalog
    {
        return new ModuleCatalog((new ModuleDiscovery($this->domainsPath))->discover());
    }
}
