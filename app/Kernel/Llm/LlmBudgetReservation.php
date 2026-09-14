<?php
declare(strict_types=1);

namespace Kernel\Llm;

use InvalidArgumentException;

final readonly class LlmBudgetReservation
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public float $maxCostAmount,
        public string $currency,
    ) {
        if (trim($this->id) === '' || trim($this->organizationId) === '') {
            throw new InvalidArgumentException('LLM budget reservation requires an id and organization id.');
        }
        if ($this->maxCostAmount <= 0.0) {
            throw new InvalidArgumentException('LLM budget reservation amount must be positive.');
        }
        if (!preg_match('/^[A-Z]{3}$/', strtoupper($this->currency))) {
            throw new InvalidArgumentException('LLM budget reservation currency must be a three-letter code.');
        }
    }
}
