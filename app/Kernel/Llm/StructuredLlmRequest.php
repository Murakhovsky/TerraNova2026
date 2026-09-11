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
        public ?string $organizationId = null,
        public ?string $useCase = null,
        public ?string $correlationId = null,
    ) {
        if (trim($this->systemPrompt) === '' || trim($this->userPrompt) === '' || $this->responseSchema === []) {
            throw new InvalidArgumentException('Structured LLM request requires prompts and a response schema.');
        }
        if ($this->maxOutputTokens !== null && $this->maxOutputTokens < 1) {
            throw new InvalidArgumentException('Structured LLM output token limit must be positive.');
        }
        if ($this->organizationId !== null && trim($this->organizationId) === '') {
            throw new InvalidArgumentException('Structured LLM organization id cannot be empty.');
        }
        if ($this->useCase !== null && !preg_match('/^[a-z][a-z0-9_.:-]*$/', $this->useCase)) {
            throw new InvalidArgumentException(sprintf('Invalid structured LLM use case: %s.', $this->useCase));
        }
        if ($this->correlationId !== null && trim($this->correlationId) === '') {
            throw new InvalidArgumentException('Structured LLM correlation id cannot be empty.');
        }
    }

    public function routedTo(string $model): self
    {
        return new self(
            $this->systemPrompt,
            $this->userPrompt,
            $this->context,
            $this->responseSchema,
            $model,
            $this->maxOutputTokens,
            $this->organizationId,
            $this->useCase,
            $this->correlationId,
        );
    }
}
