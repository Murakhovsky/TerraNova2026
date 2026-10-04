<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Infrastructure\Llm\PlatformSettingsLlmRouteResolver;
use Kernel\Llm\LlmRoute;
use Kernel\Llm\LlmRouteResolverInterface;
use Kernel\Llm\StructuredLlmRequest;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;

$settings = new class implements PlatformSettingsReaderInterface {
    public function value(string $organizationId, string $namespace, string $key, mixed $fallback = null): mixed
    {
        return match ($namespace.'.'.$key) {
            'llm.provider' => $organizationId === 'tenant-a' ? 'openai' : $fallback,
            'llm.default_model' => $organizationId === 'tenant-a' ? 'tenant-model' : $fallback,
            'engineering.developer.model' => 'tenant-coding-model',
            default => $fallback,
        };
    }
    public function secret(string $organizationId, string $namespace, string $key, ?string $fallback = null): ?string { return $fallback; }
    public function namespace(string $organizationId, string $namespace): array { return []; }
};

$fallback = new class implements LlmRouteResolverInterface {
    public function routesFor(StructuredLlmRequest $request): array { return [new LlmRoute('http', 'env-model')]; }
};

$resolver = new PlatformSettingsLlmRouteResolver($fallback, $settings);
$request = new StructuredLlmRequest(
    systemPrompt: 'system',
    userPrompt: 'user',
    context: [],
    responseSchema: ['type'=>'object'],
    organizationId: 'tenant-a',
    useCase: 'engineering.developer',
);

$route = $resolver->routesFor($request)[0];
if ($route->provider !== 'openai' || $route->model !== 'tenant-model') {
    throw new RuntimeException('Tenant LLM settings did not override ENV route.');
}

$explicit = $resolver->routesFor($request->routedTo('role-model'))[0];
if ($explicit->model !== 'role-model') throw new RuntimeException('Explicit role model must override default model.');

$unscoped = new StructuredLlmRequest(
    systemPrompt: 'system',
    userPrompt: 'user',
    context: [],
    responseSchema: ['type'=>'object'],
);
$route = $resolver->routesFor($unscoped)[0];
if ($route->provider !== 'http' || $route->model !== 'env-model') {
    throw new RuntimeException('Unscoped LLM request must retain ENV fallback routing.');
}

echo "Platform Settings LLM routing passed.\n";
