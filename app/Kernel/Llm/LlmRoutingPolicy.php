<?php
declare(strict_types=1);

namespace Kernel\Llm;

use InvalidArgumentException;

final class LlmRoutingPolicy
{
    /** @var list<LlmRoute> */
    private array $defaultRoutes;
    /** @var array<string, list<LlmRoute>> */
    private array $useCaseRoutes;

    /**
     * @param list<LlmRoute> $defaultRoutes
     * @param array<string, list<LlmRoute>> $useCaseRoutes
     */
    public function __construct(array $defaultRoutes, array $useCaseRoutes = [])
    {
        if ($defaultRoutes === []) {
            throw new InvalidArgumentException('LLM routing policy requires at least one default route.');
        }
        $this->assertRoutes($defaultRoutes, 'default');
        foreach ($useCaseRoutes as $useCase => $routes) {
            if (!is_string($useCase) || !preg_match('/^[a-z][a-z0-9_.:-]*$/', $useCase)) {
                throw new InvalidArgumentException(sprintf('Invalid LLM use case: %s.', (string) $useCase));
            }
            if ($routes === []) {
                throw new InvalidArgumentException(sprintf('LLM use case %s has no routes.', $useCase));
            }
            $this->assertRoutes($routes, $useCase);
        }

        $this->defaultRoutes = array_values($defaultRoutes);
        $this->useCaseRoutes = $useCaseRoutes;
    }

    /** @return list<LlmRoute> */
    public function routesFor(StructuredLlmRequest $request): array
    {
        $configured = $request->useCase !== null && isset($this->useCaseRoutes[$request->useCase]);
        $routes = $configured ? $this->useCaseRoutes[$request->useCase] : $this->defaultRoutes;

        // A domain-level model remains a compatibility hint only when governance has
        // no explicit use-case policy. Configured policy always wins.
        if (!$configured && $request->model !== null && trim($request->model) !== '') {
            $routes[0] = new LlmRoute($routes[0]->provider, trim($request->model));
        }

        return array_values($routes);
    }

    /** @param list<LlmRoute> $routes */
    private function assertRoutes(array $routes, string $scope): void
    {
        foreach ($routes as $route) {
            if (!$route instanceof LlmRoute) {
                throw new InvalidArgumentException(sprintf('Invalid LLM route in %s policy.', $scope));
            }
        }
    }
}
