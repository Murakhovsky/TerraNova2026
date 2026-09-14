<?php
declare(strict_types=1);

namespace Kernel\Llm;

interface StructuredLlmClientInterface
{
    public function complete(StructuredLlmRequest $request): StructuredLlmResponse;
}
