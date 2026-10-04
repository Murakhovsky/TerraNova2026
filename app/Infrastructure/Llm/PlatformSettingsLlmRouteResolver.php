<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

use Kernel\Llm\LlmRoute;
use Kernel\Llm\LlmRouteResolverInterface;
use Kernel\Llm\StructuredLlmRequest;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;

final readonly class PlatformSettingsLlmRouteResolver implements LlmRouteResolverInterface
{
    public function __construct(
        private LlmRouteResolverInterface $fallback,
        private PlatformSettingsReaderInterface $settings,
    ) {}

    public function routesFor(StructuredLlmRequest $request): array
    {
        $routes = $this->fallback->routesFor($request);
        if ($request->organizationId === null || trim($request->organizationId) === '') {
            return $routes;
        }

        $primary = $routes[0];
        $provider = trim((string) $this->settings->value(
            $request->organizationId,
            'llm',
            'provider',
            $primary->provider,
        ));
        $model = trim((string) $this->settings->value(
            $request->organizationId,
            'llm',
            'default_model',
            $primary->model,
        ));

        // Explicit role/use-case model remains the most specific model hint.
        if ($request->model !== null && trim($request->model) !== '') {
            $model = trim($request->model);
        }

        if ($provider !== '') {
            $routes[0] = new LlmRoute($provider, $model);
        }

        return array_values($routes);
    }
}
