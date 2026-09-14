<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

interface ModuleRouteContributorInterface
{
    public function register(RouterInterface $router): void;
}
