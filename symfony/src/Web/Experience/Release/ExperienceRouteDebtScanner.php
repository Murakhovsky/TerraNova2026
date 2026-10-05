<?php
declare(strict_types=1);

namespace App\Web\Experience\Release;

use App\Web\Experience\Registry\ExperienceRouteInventory;
use App\Web\Experience\Registry\PageContractRegistryInterface;
use App\Web\Experience\Registry\RouteExemptionRegistry;

final readonly class ExperienceRouteDebtScanner
{
    public function __construct(
        private ExperienceRouteInventory $inventory,
        private PageContractRegistryInterface $pages,
        private RouteExemptionRegistry $exemptions,
    ) {}

    public function scan(): ExperienceRouteDebtReport
    {
        $routes = $this->inventory->productionHtmlRoutes();
        $routeByName = [];
        foreach ($routes as $route) {
            $routeByName[$route['name']] = $route;
        }

        $missing = [];
        $stale = [];
        $mismatched = [];

        foreach ($routes as $route) {
            $contract = null;
            foreach ($this->pages->all() as $page) {
                if ($page->routeName === $route['name']) {
                    $contract = $page;
                    break;
                }
            }
            $exemption = $this->exemptions->find($route['name']);

            if ($contract === null && $exemption === null) {
                $missing[] = $route['name'];
                continue;
            }
            if ($contract !== null && $exemption !== null) {
                $mismatched[] = $route['name'].' is both contracted and exempted';
            }
            if ($contract !== null && $contract->path !== $route['path']) {
                $mismatched[] = sprintf('%s contract path mismatch: %s != %s', $route['name'], $contract->path, $route['path']);
            }
            if ($exemption !== null && $exemption->path !== $route['path']) {
                $mismatched[] = sprintf('%s exemption path mismatch: %s != %s', $route['name'], $exemption->path, $route['path']);
            }
        }

        foreach ($this->pages->all() as $page) {
            if (!isset($routeByName[$page->routeName])) {
                $stale[] = 'contract:'.$page->routeName;
            }
        }
        foreach ($this->exemptions->all() as $exemption) {
            if (!isset($routeByName[$exemption->routeName])) {
                $stale[] = 'exemption:'.$exemption->routeName;
            }
        }

        sort($missing);
        sort($stale);
        sort($mismatched);

        return new ExperienceRouteDebtReport($missing, $stale, $mismatched);
    }
}
