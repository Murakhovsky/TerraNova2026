<?php
declare(strict_types=1);

namespace Kernel\Llm;

interface StructuredLlmProgressObserverInterface
{
    public function progress(
        StructuredLlmRequest $request,
        string $provider,
        string $providerRequestId,
        string $status,
        int $pollCount,
    ): void;
}
