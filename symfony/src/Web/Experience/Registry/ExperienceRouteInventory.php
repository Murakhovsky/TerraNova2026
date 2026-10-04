<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

use Symfony\Component\Routing\RouterInterface;

final readonly class ExperienceRouteInventory
{
    public function __construct(private RouterInterface $router)
    {
    }

    /**
     * @return list<array{name:string,path:string,methods:list<string>,controller:string}>
     */
    public function productionHtmlRoutes(): array
    {
        $items = [];

        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            $path = $route->getPath();
            $controller = (string) $route->getDefault('_controller');
            $methods = $route->getMethods();

            if (!$this->isHtmlPage($name, $path, $controller, $methods)) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'path' => $path,
                'methods' => $methods === [] ? ['GET', 'HEAD'] : array_values($methods),
                'controller' => $controller,
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $items;
    }

    /** @param list<string> $methods */
    private function isHtmlPage(string $name, string $path, string $controller, array $methods): bool
    {
        if ($methods !== [] && array_intersect($methods, ['GET', 'HEAD']) === []) {
            return false;
        }

        if (preg_match('#^/(api(?:/|$)|webhooks(?:/|$)|telemetry(?:/|$)|health(?:/|$)|dev(?:/|$)|_wdt(?:/|$)|_profiler(?:/|$))#', $path)) {
            return false;
        }

        if (preg_match('/\.(?:xml|txt|json)$/', $path)) {
            return false;
        }

        return str_starts_with($controller, 'App\\Web\\')
            || str_starts_with($name, 'cos_web_')
            || $name === 'cos_symfony_home';
    }
}
