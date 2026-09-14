<?php
declare(strict_types=1);

namespace Kernel\Llm;

use InvalidArgumentException;

final readonly class LlmRoute
{
    public function __construct(
        public string $provider,
        public string $model,
    ) {
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $this->provider)) {
            throw new InvalidArgumentException(sprintf('Invalid LLM provider id: %s.', $this->provider));
        }
    }
}
