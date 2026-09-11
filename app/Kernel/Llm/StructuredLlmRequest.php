<?php
declare(strict_types=1);

namespace Kernel\Llm;

use InvalidArgumentException;

final readonly class StructuredLlmRequest
{
    public function __construct(
        public string $systemPrompt,
        public string $userPrompt,
        public array $context,
        public array $responseSchema,
        public ?string $model = null,
        public ?int $maxOutputTokens = null,
    ) {
        if (trim($this->systemPrompt) === '' || trim($this->userPrompt) === '' || $this->responseSchema === []) {
            throw new InvalidArgumentException('Structured LLM request requires prompts and a response schema.');
        }
        if ($this->maxOutputTokens !== null && $this->maxOutputTokens < 1) {
            throw new InvalidArgumentException('Structured LLM output token limit must be positive.');
        }
    }
}
