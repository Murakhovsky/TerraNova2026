<?php
declare(strict_types=1);

namespace Kernel\Llm;

interface LlmRouteResolverInterface
{
    /** @return list<LlmRoute> */
    public function routesFor(StructuredLlmRequest $request): array;
}
